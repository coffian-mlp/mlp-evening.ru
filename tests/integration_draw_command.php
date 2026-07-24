<?php
use LLM\LLMManager;
use LLM\ImageGenerator;
use Core\FileCache;
/**
 * Интеграционный тест /нарисуй (MLP-274): путь handleDrawCommand с
 * инжектированным генератором (без сети/оплаты) + дневной лимит.
 *
 * Запуск: docker compose exec php php tests/integration_draw_command.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$msgIds = [];
$optBackup = [];

try {
    $cfg = \Infra\ConfigManager::getInstance();
    foreach (['ai_enabled' => '1', 'ai_bot_user_id' => '1', 'ai_routerai_key' => 'it-dummy', 'ai_image_daily_limit' => '20', 'ai_image_llm_caption' => '0'] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }

    $llm = new LLMManager();
    $r = new ReflectionMethod(LLMManager::class, 'handleDrawCommand');
    $r->setAccessible(true);
    $cmd = ['handler_type' => 'image', 'command_prefix' => '/нарисуй', 'system_prompt' => 'ТЕСТ-СТИЛЬ:'];

    $captured = null;
    $fake = function ($prompt) use (&$captured) { $captured = $prompt; return '/upload/lyra/it_fake.jpg'; };

    echo "== успешная генерация ==\n";
    $r->invoke($llm, $cmd, ['message' => '/нарисуй пони на облаке', 'username' => 'ИтПони'], $fake);
    check(str_starts_with((string)$captured, 'ТЕСТ-СТИЛЬ:'), 'стиль-префикс из system_prompt команды');
    check(str_contains($captured, 'пони на облаке'), 'сюжет пользователя в промпте');
    $row = $conn->query("SELECT id, message FROM chat_messages ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], '![рисунок](/upload/lyra/it_fake.jpg'), 'бот запостил картинку');
    check(str_contains($row['message'], '@ИтПони'), 'адресовано автору');

    echo "== пустой запрос ==\n";
    $captured = null;
    $r->invoke($llm, $cmd, ['message' => '/нарисуй', 'username' => 'ИтПони'], $fake);
    check($captured === null, 'генератор не вызван');
    $row = $conn->query("SELECT id, message FROM chat_messages ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], 'что рисовать'), 'подсказка вместо генерации');

    echo "== сбой генератора ==\n";
    $boom = function () { return null; };
    $r->invoke($llm, $cmd, ['message' => '/нарисуй грозу', 'username' => 'ИтПони'], $boom);
    $row = $conn->query("SELECT id, message FROM chat_messages ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], 'не вышло'), 'вежливый отказ при сбое');

    echo "== дневной лимит ==\n";
    $root = sys_get_temp_dir() . '/mlp_ig_' . getmypid();
    $cache = new FileCache('', $root);
    check(ImageGenerator::todayCount($cache, '2026-07-23') === 0, 'счётчик пуст');
    ImageGenerator::bumpToday($cache, '2026-07-23');
    ImageGenerator::bumpToday($cache, '2026-07-23');
    check(ImageGenerator::todayCount($cache, '2026-07-23') === 2, 'инкремент работает');
    check(ImageGenerator::todayCount($cache, '2026-07-24') === 0, 'другой день — отдельный счётчик');
    @unlink("$root/2026-07-23.json"); @rmdir($root);

    $cfg->setOption('ai_image_daily_limit', '0'); // 0 = без лимита — генерация идёт
    $captured = null;
    $r->invoke($llm, $cmd, ['message' => '/нарисуй солнце', 'username' => 'ИтПони'], $fake);
    check($captured !== null, 'лимит 0 = безлимит');
    $row = $conn->query("SELECT id FROM chat_messages ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];

    echo "== /нарисуйчат (MLP-277) ==\n";
    $rc = new ReflectionMethod(LLMManager::class, 'handleDrawChatCommand');
    $rc->setAccessible(true);
    $cmdChat = ['handler_type' => 'image_chat', 'command_prefix' => '/нарисуйчат', 'system_prompt' => ''];

    $captured = null;
    $director = function () { return 'two ponies argue about apples while a third laughs'; };
    $rc->invoke($llm, $cmdChat, ['username' => 'ИтПони'], $director, $fake);
    check(str_contains((string)$captured, 'two ponies argue'), 'сцена режиссёра ушла в генератор');
    check(str_contains((string)$captured, 'ТЕСТ-СТИЛЬ:') || str_contains((string)$captured, 'crayon'), 'стиль-префикс применён к сцене');
    $row = $conn->query("SELECT id, message FROM chat_messages ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], '![рисунок]'), 'сценка чата запощена');

    $captured = null;
    $emptyDirector = function () { return null; };
    $rc->invoke($llm, $cmdChat, ['username' => 'ИтПони'], $emptyDirector, $fake);
    check($captured === null, 'пустая сцена — генератор не вызван');
    $row = $conn->query("SELECT id, message FROM chat_messages ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], 'рисовать-то нечего'), 'вежливый отказ на пустой чат');
} finally {
    foreach ($optBackup as $k => $v) {
        if ($v === null) $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
        else \Infra\ConfigManager::getInstance()->setOption($k, $v);
    }
    foreach (array_unique($msgIds) as $mid) if ($mid) $conn->query("DELETE FROM chat_messages WHERE id = " . (int)$mid);
    // счётчик генераций дока-теста: боевой cache/imagegen в докере — почистить сегодняшний
    @unlink(__DIR__ . '/../cache/imagegen/' . gmdate('Y-m-d') . '.json');
}

it_done();
