<?php
use Infra\ConfigManager;
use LLM\BotDispatch;
use LLM\JobQueue;
/**
 * Интеграционный тест троттла приветствий (MLP-254, v4.9.0).
 *
 * Если пользователю здоровались в пределах ai_greeting_cooldown — повторный
 * dispatch('greeting') не создаёт ни задачи в llm_jobs, ни LLM-запроса.
 * Проверяем очередь-путь (ai_use_queue=1 + daemon): вторая отбивка молчит,
 * другой пользователь — здоровается, старое приветствие (бэкдейт) — не мешает.
 *
 * Запуск: docker compose exec php php tests/integration_greeting_throttle.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

$user = 'it_greet_' . getmypid();
$cm = ConfigManager::getInstance();
$saved = [];
foreach (['ai_use_queue' => '1', 'ai_worker_mode' => 'daemon', 'ai_greeting_cooldown' => '600'] as $k => $v) {
    $saved[$k] = $cm->getOption($k, null);
    $cm->setOption($k, $v);
}
$cm->flushCache();

function jobsFor(mysqli $conn, string $user): int {
    $stmt = $conn->prepare("SELECT COUNT(*) n FROM llm_jobs WHERE type='greeting' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.username')) = ?");
    $stmt->bind_param('s', $user);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['n'];
}

try {
    // Первое приветствие — попадает в очередь.
    BotDispatch::dispatch('greeting', ['username' => $user]);
    check(jobsFor($conn, $user) === 1, 'первый вход: приветствие в очереди');

    // Повторные входы в окне троттла — молчание (ни задач, ни LLM).
    BotDispatch::dispatch('greeting', ['username' => $user]);
    BotDispatch::dispatch('greeting', ['username' => $user]);
    check(jobsFor($conn, $user) === 1, 'повторные входы в окне 10 мин: без новых задач');

    // Другой пользователь — здороваемся независимо.
    BotDispatch::dispatch('greeting', ['username' => $user . '_b']);
    check(jobsFor($conn, $user . '_b') === 1, 'другой пользователь получает своё приветствие');

    // Старое приветствие (бэкдейт за окно) — здороваемся снова.
    $stmt = $conn->prepare("UPDATE llm_jobs SET created_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE type='greeting' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.username')) = ?");
    $stmt->bind_param('s', $user);
    $stmt->execute();
    BotDispatch::dispatch('greeting', ['username' => $user]);
    check(jobsFor($conn, $user) === 2, 'окно истекло (11 мин) — поздоровались снова');

    // hasRecentByUsername / logDone напрямую (inline-путь пишет done-маркер).
    $q = new JobQueue();
    check($q->hasRecentByUsername('greeting', $user, 600) === true, 'hasRecentByUsername видит свежую задачу');
    $q->logDone('greeting', ['username' => $user . '_inline']);
    check($q->hasRecentByUsername('greeting', $user . '_inline', 600) === true, 'inline-маркер (logDone) виден троттлу');
    check($q->hasRecentByUsername('greeting', 'it_greet_nobody', 600) === false, 'незнакомец — не виден');

    // Кулдаун 0 = троттл выключен.
    $cm->setOption('ai_greeting_cooldown', '0');
    $cm->flushCache();
    BotDispatch::dispatch('greeting', ['username' => $user]);
    check(jobsFor($conn, $user) === 3, 'ai_greeting_cooldown=0 — троттл выключен');
} finally {
    $stmt = $conn->prepare("DELETE FROM llm_jobs WHERE type='greeting' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.username')) LIKE CONCAT(?, '%')");
    $stmt->bind_param('s', $user);
    $stmt->execute();
    foreach ($saved as $k => $v) {
        if ($v === null) {
            $stmt = $conn->prepare('DELETE FROM site_options WHERE key_name = ?');
            $stmt->bind_param('s', $k);
            $stmt->execute();
        } else {
            $cm->setOption($k, $v);
        }
    }
    $cm->flushCache();
}

$res = jobsFor($conn, $user) + jobsFor($conn, $user . '_b') + jobsFor($conn, $user . '_inline');
check($res === 0, 'фикстурных задач не осталось');

$conn->close();
it_done();
