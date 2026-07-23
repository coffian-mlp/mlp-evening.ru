<?php

namespace Api;

use Domain\Auth;
use Domain\UserManager;
use Infra\UploadManager;

/**
 * Обработчики API-действий администрирования пользователей (MLP-255) —
 * перенос из legacy-switch api.php в тонкий роутер. Ответы — Api\Response (MLP-262); роль (admin) проверяет роутер ДО вызова.
 */
class UserAdminController {

    /** Список всех пользователей (admin). */
    public static function getUsers(): void {
        $userManager = new UserManager();
        $users = $userManager->getAllUsers(); // с chat_color и avatar_url из user_options
        Response::json(true, "Список получен", 'success', ['users' => $users]);
    }

    /** Опции произвольного пользователя (admin). */
    public static function getUserOptions(): void {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if (!$targetUserId) Response::json(false, "ID не указан", 'error');

        $userManager = new UserManager();
        $options = $userManager->getUserOptions($targetUserId);

        Response::json(true, "Опции получены", 'success', ['options' => $options]);
    }

    /** Журнал действий модераторов (admin). */
    public static function getAuditLogs(): void {
        $userManager = new UserManager();
        $logs = $userManager->getAuditLogs();

        // Format dates for JS
        foreach ($logs as &$log) {
            if ($log['created_at']) {
                $log['created_at'] = date('Y-m-d H:i:s', strtotime($log['created_at']));
            }
        }

        Response::json(true, "Логи получены", 'success', ['logs' => $logs]);
    }

    /** Создать/обновить пользователя из карточки дашборда (admin). */
    public static function save(): void {
        $userManager = new UserManager();
        $id = $_POST['user_id'] ?? '';
        $login = trim($_POST['login'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $password = $_POST['password'] ?? '';
        // MLP-258: email из карточки ('' = снять email; уникальность проверяет updateUser)
        $email = isset($_POST['email']) ? trim($_POST['email']) : null;
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(false, "Некорректный формат Email", 'error');
        }

        // New fields & Uploads
        $chat_color = trim($_POST['chat_color'] ?? '');
        $font_preference = trim($_POST['font_preference'] ?? 'open_sans');
        $font_scale = (int)($_POST['font_scale'] ?? 100);
        $raw_avatar_url = trim($_POST['avatar_url'] ?? '');
        $avatar_url = $raw_avatar_url; // Default

        try {
            $uploadManager = new UploadManager();
            // 1. File
            if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $avatar_url = $uploadManager->uploadFromPost($_FILES['avatar_file']);
            }
            // 2. URL Download
            elseif (!empty($raw_avatar_url) && strpos($raw_avatar_url, '/upload/avatars/') !== 0 && filter_var($raw_avatar_url, FILTER_VALIDATE_URL)) {
                $avatar_url = $uploadManager->uploadFromUrl($raw_avatar_url);
            }
        } catch (\Throwable $e) {
            Response::caught($e, "Аватар: ");
        }

        if (empty($login)) Response::json(false, "Логин обязателен", 'error');
        if (empty($nickname)) $nickname = $login;

        try {
            $data = [
                'login' => $login,
                'nickname' => $nickname,
                'role' => $role,
                'avatar_url' => $avatar_url,
                'chat_color' => $chat_color,
                'font_preference' => $font_preference,
                'font_scale' => $font_scale
            ];
            if ($email !== null) {
                $data['email'] = $email;
            }

            if (!empty($id)) {
                // Update
                if (!empty($password)) {
                    if ($pwErr = Auth::validatePasswordPolicy($password)) Response::json(false, $pwErr, 'error');
                    $data['password'] = $password;
                }

                // UserManager::updateUser сам раскладывает поля users/user_options
                $userManager->updateUser($id, $data);
                Response::json(true, "Пользователь обновлен");
            } else {
                // Create
                if (empty($password)) Response::json(false, "Для нового пользователя нужен пароль", 'error');
                if ($pwErr = Auth::validatePasswordPolicy($password)) Response::json(false, $pwErr, 'error');

                // MLP-258 (ревью): email из карточки участвует и в создании
                $newId = $userManager->createUser($login, $password, $role, $nickname, $email ?? '');

                // Доп. поля (опции) — отдельным updateUser, как и раньше
                $userManager->updateUser($newId, [
                    'avatar_url' => $avatar_url,
                    'chat_color' => $chat_color,
                    'font_preference' => $font_preference,
                    'font_scale' => $font_scale
                ]);

                Response::json(true, "Пользователь создан");
            }
        } catch (\Throwable $e) {
            Response::caught($e);
        }
    }

    /** Соц-привязки произвольного пользователя (admin, MLP-258). */
    public static function getUserSocials(): void {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if (!$targetUserId) Response::json(false, "ID не указан", 'error');

        $socials = (new UserManager())->getUserSocials($targetUserId);
        Response::json(true, "Соцсети получены", 'success', ['socials' => $socials]);
    }

    /** Отвязать соцсеть у произвольного пользователя (admin, MLP-258). */
    public static function unlinkSocial(): void {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $provider = trim($_POST['provider'] ?? '');
        if (!$targetUserId || $provider === '') Response::json(false, "Данные неполные", 'error');

        if ((new UserManager())->unlinkSocial($targetUserId, $provider)) {
            Response::json(true, "Аккаунт отвязан");
        } else {
            Response::json(false, "Привязка не найдена", 'error');
        }
    }

    /** Удалить пользователя (admin). Самоудаление запрещено. */
    public static function delete(): void {
        $id = $_POST['user_id'] ?? '';
        if (empty($id)) Response::json(false, "ID не указан", 'error');

        // Не даем удалить самого себя
        if ($id == Auth::userId()) {
            Response::json(false, "Нельзя удалить самого себя!", 'error');
        }

        $userManager = new UserManager();
        if ($userManager->deleteUser($id)) {
            Response::json(true, "Пользователь удален");
        } else {
            Response::json(false, "Ошибка удаления", 'error');
        }
    }
}
