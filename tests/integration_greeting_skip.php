<?php
use LLM\JobQueue;
/**
 * Интеграционный тест MLP-294: гейт «вошёл и сразу написал» — greeting
 * пропускается, если у пользователя есть свежий mention-job (по payload.user_id;
 * по username гейтить нельзя: greeting несёт логин, mention — ник).
 *
 * Запуск: docker compose exec php php tests/integration_greeting_skip.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$q = new JobQueue();
$uid = 994422; // заведомо несуществующий пользователь — изоляция по значению
$ids = [];

try {
    echo "== hasRecentByUserId ==\n";
    check($q->hasRecentByUserId('mention', $uid, 300) === false, 'до вставки — false');

    $ids[] = $q->enqueue('mention', ['message' => 'привет, Лира', 'user_id' => $uid, 'username' => 'ИтНик'], 0);
    check($q->hasRecentByUserId('mention', $uid, 300) === true, 'свежий mention виден по user_id');
    check($q->hasRecentByUserId('mention', $uid + 1, 300) === false, 'другой user_id — false');
    check($q->hasRecentByUserId('greeting', $uid, 300) === false, 'тип фильтруется');

    // Статус не важен: done-mention (уже ответила) — greeting всё равно лишний.
    $q->complete([$ids[0]]);
    check($q->hasRecentByUserId('mention', $uid, 300) === true, 'done-mention тоже гейтит');

    // Окно: старый mention не мешает поздороваться.
    $conn->query("UPDATE llm_jobs SET created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = " . (int)$ids[0]);
    check($q->hasRecentByUserId('mention', $uid, 300) === false, 'mention старше окна — не гейтит');

    echo "\n== processTrigger('greeting') с гейтом ==\n";
    // Свежий mention снова: processTrigger обязан выйти ДО обращения к LLM
    // (провайдеры с dummy-ключом здесь бы упали/замолчали — важен именно false сразу).
    $ids[] = $q->enqueue('mention', ['message' => 'ещё раз', 'user_id' => $uid, 'username' => 'ИтНик'], 0);
    $cfg = \Infra\ConfigManager::getInstance();
    $optBackup = [];
    foreach (['ai_enabled' => '1', 'ai_bot_user_id' => '1', 'ai_routerai_key' => 'it-dummy'] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }
    try {
        $llm = new \LLM\LLMManager();
        $t0 = microtime(true);
        $res = $llm->processTrigger('greeting', ['username' => 'ItLogin', 'user_id' => $uid]);
        check($res === false, 'greeting с свежим mention → false (пропущен)');
        check(microtime(true) - $t0 < 2.0, 'выход мгновенный — LLM не вызывался');
    } finally {
        foreach ($optBackup as $k => $v) {
            if ($v === null) $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
            else $cfg->setOption($k, $v);
        }
    }
} finally {
    foreach ($ids as $id) if ($id) $conn->query("DELETE FROM llm_jobs WHERE id = " . (int)$id);
}

it_done();
