<?php
use Core\UserError;
use Domain\UserManager;
use Infra\UploadManager;
/**
 * Интеграционный тест классификации исключений (MLP-261, AR6-1).
 *
 * Проверяет разделение «пользовательские тексты» (Core\UserError — можно наружу)
 * и «системные» (всё остальное — только в error_log, наружу общий текст):
 *  1) валидационные исключения UserManager/UploadManager — instanceof UserError;
 *  2) системный сбой (данные длиннее колонки → mysqli_sql_exception) — НЕ UserError.
 * Контракты: users.contract.md, core.contract.md.
 *
 * Запуск: docker compose exec php php tests/integration_user_error.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

$login = 'it_uerr_' . getmypid();
$um = new UserManager();
$userId = null;

try {
    $userId = $um->createUser($login, 'password123', 'user', 'Ит. Пони Ошибок');
    check(is_int($userId) && $userId > 0, 'createUser вернул id (подготовка)');

    // --- 1. Валидационные исключения → UserError ---
    $caught = null;
    try {
        $um->createUser($login, 'password123'); // дубль логина
    } catch (Throwable $e) {
        $caught = $e;
    }
    check($caught instanceof UserError, 'дубль логина → Core\UserError (' . ($caught ? get_class($caught) : 'ничего') . ')');
    check($caught && str_contains($caught->getMessage(), 'существует'), 'текст дубля — пользовательский («…существует»)');

    $caught = null;
    try {
        $um->updateUser($userId, ['login' => $login === 'admin' ? 'Claude' : 'admin']); // логин сида — занят
    } catch (Throwable $e) {
        $caught = $e;
    }
    check($caught instanceof UserError, 'занятый логин в updateUser → UserError');

    $caught = null;
    try {
        (new UploadManager('avatar'))->uploadFromUrl('javascript:alert(1)');
    } catch (Throwable $e) {
        $caught = $e;
    }
    check($caught instanceof UserError, 'кривая ссылка аватара → UserError (' . ($caught ? get_class($caught) : 'ничего') . ')');
    check($caught && str_contains($caught->getMessage(), 'ссылка'), 'текст про ссылку — пользовательский');

    $caught = null;
    try {
        (new UploadManager('avatar'))->uploadFromPost(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '', 'name' => '']);
    } catch (Throwable $e) {
        $caught = $e;
    }
    check($caught instanceof UserError, 'пустой POST-аплоад → UserError');

    // --- 2. Системный сбой → НЕ UserError ---
    // login длиннее колонки users.login (VARCHAR(50)) → strict-режим MySQL 5.7
    // роняет INSERT, mysqli (режим исключений PHP 8.1+) бросает mysqli_sql_exception.
    $caught = null;
    try {
        $um->createUser(str_repeat('x', 300), 'password123');
    } catch (Throwable $e) {
        $caught = $e;
    }
    check($caught !== null, 'слишком длинный login роняет создание (системный путь достижим)');
    check(!($caught instanceof UserError), 'системный сбой — НЕ UserError (' . ($caught ? get_class($caught) : '—') . ')');

    // --- 3. UserError — подкласс Exception (обратная совместимость catch(Exception)) ---
    check(new UserError('x') instanceof Exception, 'UserError extends Exception (совместимость)');

} finally {
    if ($userId) {
        $um->deleteUser($userId);
    }
    // Уборка возможного «длинного» юзера, если strict-режим внезапно выключен и INSERT прошёл.
    $stmt = $conn->prepare("DELETE FROM users WHERE login LIKE 'xxxxxxxxxx%'");
    $stmt->execute();
}

it_done();
