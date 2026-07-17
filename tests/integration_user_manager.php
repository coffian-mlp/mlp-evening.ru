<?php
/**
 * Интеграционный тест UserManager с реальной БД (MLP-247, T-05, AC-2).
 *
 * Сценарий: createUser → чтение (byId/byLogin) → updateProfile → опции
 * пользователя → deleteUser (каскад user_options по FK) → проверка чистоты.
 * Контракт: dev_knowledge/contracts/users.contract.md. Только публичные методы.
 *
 * Запуск: docker compose exec php php tests/integration_user_manager.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

require_once __DIR__ . '/../src/UserManager.php';

$login = 'it_user_' . getmypid();
$um = new UserManager();

$userId = null;
try {
// --- Создание ---
$userId = $um->createUser($login, 'password123', 'user', 'Ит. Пони', null);
check(is_int($userId) && $userId > 0, "createUser вернул id (= " . var_export($userId, true) . ")");

// Дубль логина — исключение (error path).
$dupCaught = false;
try {
    $um->createUser($login, 'password123');
} catch (Exception $e) {
    $dupCaught = true;
}
check($dupCaught, 'createUser с занятым логином бросает исключение');

// --- Чтение ---
$user = $um->getUserById($userId);
check(is_array($user) && $user['login'] === $login, 'getUserById возвращает пользователя');
check($user['nickname'] === 'Ит. Пони', 'nickname сохранён');

$byLogin = $um->getUserByLogin($login);
check(is_array($byLogin) && (int)$byLogin['id'] === $userId, 'getUserByLogin находит того же пользователя');
check(isset($byLogin['password_hash']) && password_verify('password123', $byLogin['password_hash']), 'пароль захэширован (bcrypt), верифицируется');

// --- Дефолтные опции создания ---
$opts = $um->getUserOptions($userId);
check(!empty($opts['chat_color']), 'createUser выдал случайный chat_color');
check(!empty($opts['avatar_url']), 'createUser выдал дефолтный avatar_url');

// --- Опции пользователя ---
check($um->setUserOption($userId, 'it_pref', 'twilight') !== false, 'setUserOption записывает опцию');
check($um->getUserOptions($userId, 'it_pref') === 'twilight', 'getUserOptions читает опцию по ключу');

// --- Обновление профиля ---
$um->updateUser($userId, ['nickname' => 'Ит. Пони 2']);
$updated = $um->getUserById($userId);
check($updated['nickname'] === 'Ит. Пони 2', 'updateUser обновил nickname');

// --- Удаление ---
check($um->deleteUser($userId) === true, 'deleteUser удаляет пользователя');
check($um->getUserById($userId) === null || $um->getUserById($userId) === false, 'после удаления getUserById пуст');
} finally {
    // Уборка при исключении посреди теста: deleteUser идемпотентен (DELETE).
    if ($userId) {
        $um->deleteUser($userId);
    }
}

// --- Чистота БД (только свои данные — по точному логину/id) ---
$stmt = $conn->prepare('SELECT COUNT(*) AS n FROM users WHERE login = ?');
$stmt->bind_param('s', $login);
$stmt->execute();
check((int)$stmt->get_result()->fetch_assoc()['n'] === 0, 'своего тестового пользователя не осталось');
$stmt->close();
$stmt = $conn->prepare('SELECT COUNT(*) AS n FROM user_options WHERE user_id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
check((int)$stmt->get_result()->fetch_assoc()['n'] === 0, 'user_options вычищены каскадом FK');
$stmt->close();

$conn->close();
it_done();
