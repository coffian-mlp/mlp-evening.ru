<?php
use Domain\PollManager;
use Domain\UserManager;
/**
 * Интеграционный тест авто-закрытия опросов по closes_at (MLP-251).
 *
 * Просроченный опрос закрывается closeExpired() (и лениво через getPoll/
 * listActive); бессрочный и будущий остаются открытыми; голос в просроченный
 * отклоняется. Broadcast не ходит в сеть (centrifugo_api_key пуст).
 *
 * Запуск: docker compose exec php php tests/integration_poll_close.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

$um = new UserManager();
$pm = new PollManager();
$uid = $um->createUser('it_user_poll_' . getmypid(), 'password123', 'user', 'Ит. Опросница');

$created = [];
try {
    $past   = gmdate('Y-m-d H:i:s', time() - 60);
    $future = gmdate('Y-m-d H:i:s', time() + 3600);

    $created[] = $expired = $pm->create($uid, 'Ит: просроченный?', ['да', 'нет'], false, false, $past);
    $created[] = $endless = $pm->create($uid, 'Ит: бессрочный?', ['да', 'нет']);
    $created[] = $later   = $pm->create($uid, 'Ит: будущий?', ['да', 'нет'], false, false, $future);

    $closed = $pm->closeExpired();
    check($closed >= 1, "closeExpired закрыл просроченные (>= 1, фактически $closed)");

    check($pm->getPoll($expired)['status'] === 'closed', 'просроченный — closed (и closed_at проставлен: ' . var_export($pm->getPoll($expired)['closed_at'] !== null, true) . ')');
    check($pm->getPoll($endless)['status'] === 'open', 'бессрочный — open');
    check($pm->getPoll($later)['status'] === 'open', 'с будущим closes_at — open');

    // Голос в просроченный отклоняется (vote → getPoll → ленивое закрытие).
    $optId = (int)$pm->getPoll($expired)['options'][0]['id'];
    check($pm->vote($expired, $uid, [$optId]) === false, 'голос в просроченный опрос отклонён');

    // listActive не отдаёт просроченный.
    $activeIds = array_map(fn($p) => (int)$p['id'], $pm->listActive());
    check(!in_array($expired, $activeIds, true), 'listActive не содержит просроченный');
    check(in_array($endless, $activeIds, true), 'listActive содержит бессрочный');
} finally {
    // Фикстурная уборка: у PollManager нет физического удаления опроса.
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $conn->query("DELETE FROM poll_votes WHERE poll_id IN ($in)");
        $conn->query("DELETE FROM poll_options WHERE poll_id IN ($in)");
        $conn->query("DELETE FROM polls WHERE id IN ($in)");
    }
    $um->deleteUser($uid);
}

$res = $conn->query("SELECT COUNT(*) n FROM polls WHERE question LIKE 'Ит: %'");
check((int)$res->fetch_assoc()['n'] === 0, 'тестовых опросов не осталось');

$conn->close();
it_done();
