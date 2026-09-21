<?php
use Domain\OnlineManager;
/**
 * Интеграционный тест MLP-319: OnlineManager::touchUser — штамп присутствия в users.last_seen
 * и зазор с прошлой отметки (секунды, на стороне MySQL). Вместе с чистым isArrival даёт
 * сценарий «зритель с remember-me появился после N часов → приветствие».
 *
 * Запуск: docker compose exec php php tests/integration_online_greeting.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$marker = 'it319_' . getmypid();
$stmt = $conn->prepare("INSERT INTO users (login, nickname, email, password_hash, role) VALUES (?, ?, ?, 'x', 'user')");
$login = $marker; $nick = $marker . '_nick'; $email = $marker . '@it.test';
$stmt->bind_param('sss', $login, $nick, $email);
$stmt->execute();
$uid = (int)$stmt->insert_id;

try {
    $om = new OnlineManager();

    echo "== первая отметка ==\n";
    $t = $om->touchUser($uid);
    check(is_array($t) && $t['login'] === $login, 'touchUser возвращает login');
    check($t['gap'] === null, 'до первой отметки gap = null');
    check(OnlineManager::isArrival($t['gap'], 4 * 3600) === false, 'первая отметка — не приход');

    echo "\n== повторный heartbeat ==\n";
    $t = $om->touchUser($uid);
    check(is_int($t['gap']) && $t['gap'] >= 0 && $t['gap'] < 60, "сразу после штампа gap мал ({$t['gap']} с)");
    check(OnlineManager::isArrival($t['gap'], 4 * 3600) === false, 'обычный heartbeat — не приход');

    echo "\n== возвращение после отсутствия ==\n";
    $conn->query("UPDATE users SET last_seen = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 HOUR) WHERE id = {$uid}");
    $t = $om->touchUser($uid);
    check($t['gap'] >= 5 * 3600 - 5 && $t['gap'] <= 5 * 3600 + 60, "gap ≈ 5 ч ({$t['gap']} с)");
    check(OnlineManager::isArrival($t['gap'], 4 * 3600) === true, 'порог 4 ч → приход');
    check(OnlineManager::isArrival($t['gap'], 6 * 3600) === false, 'порог 6 ч → ещё не приход');
    $row = $conn->query("SELECT TIMESTAMPDIFF(SECOND, last_seen, UTC_TIMESTAMP()) g FROM users WHERE id = {$uid}")->fetch_assoc();
    check((int)$row['g'] < 60, 'после touchUser отметка снова свежая');

    echo "\n== несуществующий пользователь ==\n";
    check($om->touchUser(0) === null, 'id 0 → null');
} finally {
    $conn->query("DELETE FROM users WHERE id = {$uid}");
}

it_done();
