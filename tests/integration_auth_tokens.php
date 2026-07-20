<?php
use Domain\Auth;
use Domain\UserManager;
/**
 * Интеграционный тест Auth-хвостов (MLP-250): GC remember-токенов (AR2-3)
 * и логин через владельца users (AR2-2).
 *
 * Фикстурой вставляем истёкший и живой токены (auth_tokens принадлежит Auth,
 * публичного insert кроме issueRememberToken с cookie-side-effect нет — прямая
 * вставка здесь фикстура, не бизнес-логика), зовём Auth::gcExpiredTokens():
 * истёкший удалён, живой цел.
 *
 * Запуск: docker compose exec php php tests/integration_auth_tokens.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

// Сессию стартуем ДО первого echo (после вывода в CLI нельзя — headers already sent).
Auth::check();

$mark = 'it_gc_' . getmypid();
$selExpired = substr($mark . '_old________________', 0, 24);
$selAlive   = substr($mark . '_new________________', 0, 24);

try {
    $stmt = $conn->prepare("INSERT INTO auth_tokens (selector, validator_hash, user_id, expires_at) VALUES (?, ?, 0, ?)");
    $hash = str_repeat('a', 64);
    $past   = gmdate('Y-m-d H:i:s', time() - 3600);
    $future = gmdate('Y-m-d H:i:s', time() + 3600);
    $stmt->bind_param('sss', $selExpired, $hash, $past);
    $stmt->execute();
    $stmt->bind_param('sss', $selAlive, $hash, $future);
    $stmt->execute();
    $stmt->close();

    $deleted = Auth::gcExpiredTokens();
    check($deleted >= 1, "gcExpiredTokens удалил истёкшие (>= 1, фактически $deleted)");

    $stmt = $conn->prepare('SELECT selector FROM auth_tokens WHERE selector IN (?, ?)');
    $stmt->bind_param('ss', $selExpired, $selAlive);
    $stmt->execute();
    $left = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'selector');
    $stmt->close();

    check(!in_array($selExpired, $left, true), 'истёкший токен удалён');
    check(in_array($selAlive, $left, true), 'живой токен не тронут');
} finally {
    $stmt = $conn->prepare("DELETE FROM auth_tokens WHERE selector IN (?, ?)");
    $stmt->bind_param('ss', $selExpired, $selAlive);
    $stmt->execute();
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) n FROM auth_tokens WHERE selector LIKE CONCAT(?, '%')");
$stmt->bind_param('s', $mark);
$stmt->execute();
check((int)$stmt->get_result()->fetch_assoc()['n'] === 0, 'фикстурных токенов не осталось');
$stmt->close();

// --- AR2-2: Auth::login работает через UserManager::getUserByLogin ---
$um = new UserManager();
$login = 'it_user_auth_' . getmypid();
$uid = $um->createUser($login, 'password123', 'user', 'Ит. Аутентика');
// CLI: session_regenerate_id внутри login ругается на отправленные заголовки —
// это артефакт консоли (вывод check() выше), не поведения; глушим только warning'и.
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
try {
    check(Auth::login($login, 'password123') === true, 'Auth::login с верным паролем — true');
    check(($_SESSION['user_id'] ?? null) === $uid, 'сессия получила user_id');
    check(Auth::login($login, 'wrong-password') === false, 'Auth::login с неверным паролем — false');
    check(Auth::login('it_no_such_user', 'x') === false, 'Auth::login несуществующего — false');
} finally {
    error_reporting($prevLevel);
    $_SESSION = [];
    $um->deleteUser($uid);
}

$conn->close();
it_done();
