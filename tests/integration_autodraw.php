<?php
/**
 * MLP-316: авто-/нарисуйчат — планировщик BotWorker::autoDrawSchedule (Reflection)
 * и авто-режим LyraArtist::handleDrawChat (тихие отказы, безадресная подпись).
 *
 * Запуск: docker compose exec php php tests/integration_autodraw.php
 */

require_once __DIR__ . '/integration_helpers.php';

use LLM\BotWorker;
use LLM\LyraArtist;

$conn = it_require_db();
$marker = 'itad_' . getmypid();

$optBackup = [];
$cleanupUserIds = [];
$cleanupMsgIds = [];
$baseJobId = (int)($conn->query("SELECT COALESCE(MAX(id), 0) m FROM llm_jobs")->fetch_assoc()['m']);
// JSON-колонка нормализует пробелы («"auto": true») — LIKE по сырой строке мимо, только JSON_EXTRACT.
$myJobs = fn() => (int)$conn->query("SELECT COUNT(*) c FROM llm_jobs WHERE id > $baseJobId AND type='dynamic_command' AND JSON_EXTRACT(payload, '$.auto') = true")->fetch_assoc()['c'];

try {
    $cfg = \Infra\ConfigManager::getInstance();

    $stmt = $conn->prepare("INSERT INTO users (login, nickname, email, password_hash, role) VALUES (?, ?, ?, 'x', 'user')");
    foreach ([["{$marker}_bot", "{$marker}_Лира"], ["{$marker}_human", "{$marker}_Пони"]] as [$l, $n]) {
        $e = $l . '@test.local';
        $stmt->bind_param('sss', $l, $n, $e);
        $stmt->execute();
        $cleanupUserIds[] = (int)$stmt->insert_id;
    }
    [$botId, $humanId] = $cleanupUserIds;

    foreach (['ai_enabled' => '1', 'ai_bot_user_id' => (string)$botId, 'ai_routerai_key' => 'it-dummy',
              'ai_image_auto_interval' => '0', 'bot_last_autodraw' => '0',
              'ai_image_daily_limit' => '0', 'ai_image_llm_caption' => '0'] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }

    $ins = function (int $uid, string $text) use ($conn, $marker, &$cleanupMsgIds): int {
        $stmt = $conn->prepare("INSERT INTO chat_messages (user_id, username, message, created_at) VALUES (?, ?, ?, NOW())");
        $u = $marker;
        $stmt->bind_param('iss', $uid, $u, $text);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $cleanupMsgIds[] = $id;
        return $id;
    };

    $w = new BotWorker();
    $sched = new ReflectionMethod(BotWorker::class, 'autoDrawSchedule');
    $sched->setAccessible(true);

    // 1) Выключено (interval=0) -> job нет даже при живом чате
    $ins($humanId, "{$marker} живой чат");
    $sched->invoke($w);
    check($myJobs() === 0, 'interval=0: авто-рисование выключено, job нет');

    // 2) Включено + живой чат -> job создан, маркер времени записан
    $cfg->setOption('ai_image_auto_interval', '900');
    $sched->invoke($w);
    check($myJobs() === 1, 'interval=900 + живой чат: job создан');
    check((int)$cfg->getOption('bot_last_autodraw', 0) > time() - 5, 'bot_last_autodraw записан (ДО enqueue)');
    $res = $conn->query("SELECT payload FROM llm_jobs WHERE id > $baseJobId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $payload = json_decode($res['payload'], true);
    check(($payload['auto'] ?? false) === true && ($payload['command']['handler_type'] ?? '') === 'image_chat',
        'payload: auto=true, команда image_chat из БД');

    // 3) Повторный тик сразу -> интервал не пустит
    $sched->invoke($w);
    check($myJobs() === 1, 'интервал не истёк: второго job нет');

    // 4) Последнее сообщение — бота -> не рисуем (мёртвый/свой чат)
    $cfg->setOption('bot_last_autodraw', '0');
    $ins($botId, "{$marker} реплика бота последняя");
    $sched->invoke($w);
    check($myJobs() === 1, 'последнее сообщение бота: job не создан');

    // 5) handleDrawChat auto: сцены нет -> ТИШИНА (не постим «рисовать нечего»)
    $llm = new LLM\LLMManager();
    $artist = new LyraArtist($llm);
    $msgBefore = (int)$conn->query("SELECT COALESCE(MAX(id),0) m FROM chat_messages")->fetch_assoc()['m'];
    $cmd = ['handler_type' => 'image_chat', 'command_prefix' => '/нарисуйчат', 'system_prompt' => 'ТЕСТ-СТИЛЬ: {без техник}'];
    $artist->handleDrawChat($cmd, ['username' => "{$marker}_Лира", 'auto' => true], fn() => null, fn() => null);
    $c = (int)$conn->query("SELECT COUNT(*) c FROM chat_messages WHERE id > $msgBefore")->fetch_assoc()['c'];
    check($c === 0, 'auto + пустая сцена: тишина (отказ не постится)');

    // 6) Не-auto: отказ постится как раньше (регресс)
    $artist->handleDrawChat($cmd, ['username' => "{$marker}_Пони"], fn() => null, fn() => null);
    $row = $conn->query("SELECT id, message FROM chat_messages WHERE id > $msgBefore ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check($row && str_contains($row['message'], 'рисовать-то нечего'), 'ручной режим: отказ постится (регресс цел)');
    $cleanupMsgIds[] = (int)$row['id'];

    // 7) auto-успех: безадресная подпись с рисунком
    $msgBefore = (int)$conn->query("SELECT COALESCE(MAX(id),0) m FROM chat_messages")->fetch_assoc()['m'];
    $artist->handleDrawChat($cmd, ['username' => "{$marker}_Лира", 'auto' => true],
        fn() => 'two ponies discussing tests', fn() => '/upload/lyra/test.jpg');
    $row = $conn->query("SELECT id, message FROM chat_messages WHERE id > $msgBefore ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check($row && str_contains($row['message'], '![рисунок]'), 'auto-успех: рисунок запощен');
    check(!str_contains($row['message'], '@'), 'auto-подпись безадресная (без @)');
    $cleanupMsgIds[] = (int)$row['id'];

} finally {
    $conn->query("DELETE FROM llm_jobs WHERE id > $baseJobId AND type='dynamic_command' AND JSON_EXTRACT(payload, '$.auto') = true");
    if ($cleanupMsgIds) {
        $conn->query("DELETE FROM chat_messages WHERE id IN (" . implode(',', array_map('intval', array_unique($cleanupMsgIds))) . ")");
    }
    $conn->query("DELETE FROM chat_messages WHERE username = '{$marker}'");
    if ($cleanupUserIds) {
        $conn->query("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $cleanupUserIds)) . ")");
    }
    if ($optBackup) {
        foreach ($optBackup as $k => $v) {
            if ($v === null) {
                $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
            } else {
                $cfg->setOption($k, (string)$v);
            }
        }
        $cfg->flushCache();
    }
}

it_done();
