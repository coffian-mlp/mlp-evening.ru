<?php
use LLM\LLMManager;
use LLM\LyraArtist;
use LLM\ImageGenerator;
use Core\FileCache;
/**
 * Интеграционный тест /нарисуй (MLP-274): путь LyraArtist::handleDraw с
 * инжектированным генератором (без сети/оплаты) + дневной лимит.
 * MLP-284: художница вынесена в LLM\LyraArtist — методы публичные, без рефлексии.
 *
 * Запуск: docker compose exec php php tests/integration_draw_command.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$msgIds = [];
$optBackup = [];
$appearanceMemoryId = null;
$fixtureBotId = (new \Domain\UserManager())->createUser('it_user_draw_' . bin2hex(random_bytes(6)), bin2hex(random_bytes(24)), 'user');

try {
    $cfg = \Infra\ConfigManager::getInstance();
    foreach (['ai_enabled' => '1', 'ai_bot_user_id' => (string)$fixtureBotId, 'ai_routerai_key' => 'it-dummy', 'ai_image_daily_limit' => '20', 'ai_image_llm_caption' => '0', 'ai_memory_enabled' => '1'] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }

    $llm = new LLMManager();
    $artist = new LyraArtist($llm);
    $cmd = ['handler_type' => 'image', 'command_prefix' => '/нарисуй', 'system_prompt' => 'ТЕСТ-СТИЛЬ:'];

    $captured = null;
    $imageSequence = 0;
    $lastFakeUrl = null;
    $fake = function ($prompt) use (&$captured, &$imageSequence, &$lastFakeUrl, $fixtureBotId) {
        $captured = $prompt;
        return $lastFakeUrl = '/upload/lyra/it_fake_' . $fixtureBotId . '_' . (++$imageSequence) . '.jpg';
    };

    echo "== успешная генерация ==\n";
    $artist->handleDraw($cmd, ['message' => '/нарисуй пони на облаке', 'username' => 'ИтПони'], $fake);
    check(str_starts_with((string)$captured, 'ТЕСТ-СТИЛЬ:'), 'стиль-префикс из system_prompt команды');
    check(str_contains($captured, 'пони на облаке'), 'сюжет пользователя в промпте');
    $row = $conn->query("SELECT id, message FROM chat_messages WHERE user_id=$fixtureBotId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], '![рисунок](' . $lastFakeUrl), 'бот запостил картинку');
    check(str_contains($row['message'], '@ИтПони'), 'адресовано автору');

    echo "== явный участник в сюжете ==\n";
    $mentionLlm = new class extends LLMManager {
        public int $targetId;
        public array $mentionRequests = [];
        public array $sceneRequests = [];
        public bool $directorAvailable = true;
        public function commandMentionContext(string $text): array {
            $this->mentionRequests[] = $text;
            return ['users' => [['id' => $this->targetId, 'login' => 'wheat_user', 'nickname' => 'Пшеница']], 'memory' => 'PRIVATE_DRAW_TEST_DOSSIER'];
        }
        public function generateUtility(array $context, string $systemPrompt, ?int $deadlineSec = null, ?int $timeoutSec = null): ?string {
            $this->sceneRequests[] = [$context, $systemPrompt];
            return $this->directorAvailable ? 'Пшеница, the named participant, sits beside a pastry and a blue cup, without lettering.' : null;
        }
    };
    $mentionLlm->targetId = $fixtureBotId;
    $appearanceMemoryId = (new \Domain\BotMemoryManager())->add('dossier', $fixtureBotId, 'внешность: grey pony with a blue mane', 'manual', $fixtureBotId);
    $mentionedArtist = new LyraArtist($mentionLlm);
    $request = 'пирожок с @Пшеница, синяя чашка; без надписей';
    $mentionedArtist->handleDraw($cmd, ['message' => '/нарисуй ' . $request, 'username' => 'ИтПони'], $fake);
    check($mentionLlm->mentionRequests === [$request], 'общий resolver получает полный запрос без префикса');
    check(count($mentionLlm->sceneRequests) === 1, 'режиссёр сцены вызывается для явного участника');
    check(str_contains($captured, $request) && str_contains($captured, 'named participant'), 'генератор получает исходный сюжет и трактовку участника');
    check(!str_contains($captured, 'PRIVATE_DRAW_TEST_DOSSIER'), 'генератор не получает исходное досье');
    $row = $conn->query("SELECT id, message FROM chat_messages WHERE user_id=$fixtureBotId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(!str_contains($row['message'], 'PRIVATE_DRAW_TEST_DOSSIER') && str_contains($row['message'], '@ИтПони'), 'подпись не раскрывает досье и сохраняет адресата');

    $mentionLlm->directorAvailable = false;
    $errorProperty = new ReflectionProperty(ImageGenerator::class, 'lastError');
    $originalImageError = $errorProperty->getValue();
    $attempts = [];
    try {
        $retryGenerator = function ($prompt) use (&$attempts, $errorProperty, $fake) {
            $attempts[] = $prompt;
            if (count($attempts) === 1) {
                $errorProperty->setValue(null, 'Output content violated imagegen safety policies.');
                return null;
            }
            return $fake($prompt);
        };
        $mentionedArtist->handleDraw($cmd, ['message' => '/нарисуй ' . str_repeat('long scene ', 80) . $request, 'username' => 'ИтПони'], $retryGenerator);
        check(count($attempts) === 2 && str_contains($attempts[1], 'Пшеница') && str_contains($attempts[1], 'grey pony with a blue mane'),
            'safety retry сохраняет явного участника и существующий облик при длинном запросе и мёртвом режиссёре');
        check(!str_contains($attempts[1], 'PRIVATE_DRAW_TEST_DOSSIER'), 'безопасный повтор не раскрывает исходное досье');
    } finally {
        $errorProperty->setValue(null, $originalImageError);
        $mentionLlm->directorAvailable = true;
    }

    echo "== пустой запрос ==\n";
    $captured = null;
    $artist->handleDraw($cmd, ['message' => '/нарисуй', 'username' => 'ИтПони'], $fake);
    check($captured === null, 'генератор не вызван');
    $row = $conn->query("SELECT id, message FROM chat_messages WHERE user_id=$fixtureBotId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], 'что рисовать'), 'подсказка вместо генерации');

    echo "== сбой генератора ==\n";
    $boom = function () { return null; };
    $artist->handleDraw($cmd, ['message' => '/нарисуй грозу', 'username' => 'ИтПони'], $boom);
    $row = $conn->query("SELECT id, message FROM chat_messages WHERE user_id=$fixtureBotId ORDER BY id DESC LIMIT 1")->fetch_assoc();
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

    $cfg->setOption('ai_image_daily_limit', '1');
    $captured = null;
    $directorCalls = count($mentionLlm->sceneRequests);
    $mentionCalls = count($mentionLlm->mentionRequests);
    $mentionedArtist->handleDraw($cmd, ['message' => '/нарисуй ' . $request, 'username' => 'ИтПони'], $fake);
    check($captured === null && count($mentionLlm->sceneRequests) === $directorCalls && count($mentionLlm->mentionRequests) === $mentionCalls,
        'исчерпанный лимит останавливает resolver, режиссёра и генератор');

    $cfg->setOption('ai_image_daily_limit', '0'); // 0 = без лимита — генерация идёт
    $captured = null;
    $artist->handleDraw($cmd, ['message' => '/нарисуй солнце', 'username' => 'ИтПони'], $fake);
    check($captured !== null, 'лимит 0 = безлимит');
    $row = $conn->query("SELECT id FROM chat_messages WHERE user_id=$fixtureBotId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];

    echo "== /нарисуйчат (MLP-277) ==\n";
    $cmdChat = ['handler_type' => 'image_chat', 'command_prefix' => '/нарисуйчат', 'system_prompt' => ''];

    $captured = null;
    $director = function () { return 'two ponies argue about apples while a third laughs'; };
    $artist->handleDrawChat($cmdChat, ['username' => 'ИтПони'], $director, $fake);
    check(str_contains((string)$captured, 'two ponies argue'), 'сцена режиссёра ушла в генератор');
    check(str_contains((string)$captured, 'ТЕСТ-СТИЛЬ:') || str_contains((string)$captured, 'crayon'), 'стиль-префикс применён к сцене');
    $row = $conn->query("SELECT id, message FROM chat_messages WHERE user_id=$fixtureBotId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], '![рисунок]'), 'сценка чата запощена');

    $captured = null;
    $emptyDirector = function () { return null; };
    $artist->handleDrawChat($cmdChat, ['username' => 'ИтПони'], $emptyDirector, $fake);
    check($captured === null, 'пустая сцена — генератор не вызван');
    $row = $conn->query("SELECT id, message FROM chat_messages WHERE user_id=$fixtureBotId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], 'рисовать-то нечего'), 'вежливый отказ на пустой чат');
} finally {
    foreach ($optBackup as $k => $v) {
        if ($v === null) $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
        else \Infra\ConfigManager::getInstance()->setOption($k, $v);
    }
    foreach (array_unique($msgIds) as $mid) if ($mid) $conn->query("DELETE FROM chat_messages WHERE id = " . (int)$mid);
    $conn->query('DELETE FROM chat_messages WHERE user_id=' . (int)$fixtureBotId);
    if ($appearanceMemoryId) (new \Domain\BotMemoryManager())->delete((int)$appearanceMemoryId);
    (new \Domain\UserManager())->deleteUser($fixtureBotId);
    // счётчик генераций дока-теста: боевой cache/imagegen в докере — почистить сегодняшний
    @unlink(__DIR__ . '/../cache/imagegen/' . gmdate('Y-m-d') . '.json');
}

it_done();
