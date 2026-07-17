<?php
/**
 * Интеграционный тест ChatManager с реальной БД (MLP-247, T-06, AC-2).
 *
 * Сценарий: addMessage → getMessageById → editMessage → deleteMessage (soft)
 * → restoreMessage → очистка. Автор — временный it_user_* (UserManager).
 * Контракт: dev_knowledge/contracts/chat.contract.md. Только публичные методы;
 * broadcast не ходит в сеть (centrifugo_api_key пуст в тестовом config.php).
 *
 * Запуск: docker compose exec php php tests/integration_chat_manager.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

require_once __DIR__ . '/../src/UserManager.php';
require_once __DIR__ . '/../src/ChatManager.php';

$um = new UserManager();
$cm = new ChatManager();

$login  = 'it_user_chat_' . getmypid();
$userId = $um->createUser($login, 'password123', 'user', 'Ит. Чат-Пони');
check(is_int($userId) && $userId > 0, 'тестовый пользователь создан');

$msgId = null;
try {
    // --- Добавление ---
    $msgId = $cm->addMessage($userId, 'Ит. Чат-Пони', 'Интеграционное сообщение ✨');
    check(is_int($msgId) && $msgId > 0, "addMessage вернул id (= " . var_export($msgId, true) . ")");

    // Анти-дубль: сразу после первого addMessage (окно всего 3 секунды —
    // любые промежуточные запросы могут его закрыть, см. ревью MLP-247).
    check($cm->addMessage($userId, 'Ит. Чат-Пони', 'Интеграционное сообщение ✨') === true, 'дубль в течение 3с игнорируется');

    $msg = $cm->getMessageById($msgId);
    check(is_array($msg), 'getMessageById возвращает сообщение');
    check(strpos($msg['message'] ?? '', 'Интеграционное сообщение') !== false, 'текст сохранён');

    // Пустое сообщение не добавляется (error path).
    check($cm->addMessage($userId, 'Ит. Чат-Пони', '   ') === false, 'пустое сообщение отклонено');

    // --- Редактирование (своё, свежее — в пределах окна) ---
    $edited = $cm->editMessage($msgId, $userId, 'Отредактировано интеграционным тестом');
    check($edited !== false, 'editMessage редактирует своё свежее сообщение');
    $msg = $cm->getMessageById($msgId);
    check(strpos($msg['message'] ?? '', 'Отредактировано') !== false, 'новый текст виден');

    // --- Soft-delete и восстановление ---
    check($cm->deleteMessage($msgId, $userId) !== false, 'deleteMessage помечает удалённым');
    $row = $conn->query("SELECT is_deleted FROM chat_messages WHERE id = " . (int)$msgId)->fetch_assoc();
    check((int)($row['is_deleted'] ?? 0) === 1, 'в БД is_deleted = 1 (soft, не физическое удаление)');

    check($cm->restoreMessage($msgId, $userId) !== false, 'restoreMessage возвращает сообщение');
    $row = $conn->query("SELECT is_deleted FROM chat_messages WHERE id = " . (int)$msgId)->fetch_assoc();
    check((int)($row['is_deleted'] ?? 1) === 0, 'в БД is_deleted = 0 после восстановления');

    // --- Бан блокирует отправку (доменное правило, error path) ---
    $um->banUser($userId, 'интеграционный тест');
    $banCaught = false;
    try {
        $cm->addMessage($userId, 'Ит. Чат-Пони', 'Сообщение из бана');
    } catch (Exception $e) {
        $banCaught = true;
    }
    check($banCaught, 'забаненный пользователь получает исключение при addMessage');
    $um->unbanUser($userId);
} finally {
    // Очистка. У ChatManager нет физического удаления одного сообщения
    // (purge — soft-delete) — фикстурная уборка тестовых строк напрямую.
    $stmt = $conn->prepare('DELETE FROM chat_messages WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    $um->deleteUser($userId);
}

// --- Чистота БД (только свои данные — по точному id/логину) ---
$stmt = $conn->prepare('SELECT COUNT(*) AS n FROM chat_messages WHERE user_id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
check((int)$stmt->get_result()->fetch_assoc()['n'] === 0, 'сообщений тестового пользователя не осталось');
$stmt->close();
$stmt = $conn->prepare('SELECT COUNT(*) AS n FROM users WHERE login = ?');
$stmt->bind_param('s', $login);
$stmt->execute();
check((int)$stmt->get_result()->fetch_assoc()['n'] === 0, 'тестового пользователя не осталось');
$stmt->close();

$conn->close();
it_done();
