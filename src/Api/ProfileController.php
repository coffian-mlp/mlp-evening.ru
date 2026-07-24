<?php

namespace Api;

use Domain\Auth;
use Domain\UserManager;
use Infra\UploadManager;

/**
 * Обработчики профиля и его соц-привязок (MLP-264, срез AR5-6) — перенос из
 * api.php вербатим. Ответы — Api\Response; роль user проверяет роутер.
 */
class ProfileController {

    /** Обновление профиля: ник/email/логин/цвет/шрифт/аватар/пароль (user). */
    public static function update(): void {
        $userId = Auth::userId();
        $data = [];

        if (isset($_POST['nickname'])) {
            $nick = trim($_POST['nickname']);
            if (empty($nick)) Response::json(false, "Никнейм не может быть пустым", 'error');
            $data['nickname'] = $nick;
        }

        if (isset($_POST['email'])) {
            $email = trim($_POST['email']);
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::json(false, "Некорректный Email", 'error');
            }
            $data['email'] = $email; // пустая строка = снять email (уникальность — в updateUser)
        }

        if (isset($_POST['login'])) {
            $login = trim($_POST['login']);
            if (mb_strlen($login) < 3) Response::json(false, "Логин слишком короткий", 'error');
            $data['login'] = $login;
        }

        if (isset($_POST['chat_color'])) {
            $color = trim($_POST['chat_color']);
            if (!preg_match('/^#[a-fA-F0-9]{6}$/', $color)) $color = '#6d2f8e';
            $data['chat_color'] = $color;
        }

        if (isset($_POST['font_preference'])) {
            $font = trim($_POST['font_preference']);
            $allowedFonts = ['open_sans', 'fira', 'pt', 'rubik', 'inter'];
            if (in_array($font, $allowedFonts)) {
                $data['font_preference'] = $font;
            }
        }

        if (isset($_POST['font_scale'])) {
            $scale = (int)$_POST['font_scale'];
            if ($scale < 50) $scale = 50;
            if ($scale > 150) $scale = 150;
            $data['font_scale'] = $scale;
        }

        if (isset($_POST['avatar_url']) || isset($_FILES['avatar_file'])) {
            try {
                // AR6-5: общий резолвер «файл приоритетнее URL»; невалидная внешняя
                // ссылка отбивается UserError. MLP-287: тройка аргументов — в resolveAvatar().
                $data['avatar_url'] = (new UploadManager())->resolveAvatar();
            } catch (\Throwable $e) {
                Response::caught($e, "Аватар: ");
            }
        }

        if (!empty($_POST['password'])) {
            if ($pwErr = Auth::validatePasswordPolicy($_POST['password'])) Response::json(false, $pwErr, 'error');
            $data['password'] = $_POST['password'];
        }

        try {
            (new UserManager())->updateProfile($userId, $data);
            if (isset($data['nickname'])) Auth::setUsername($data['nickname']); // MLP-287: сессия — только через Auth
            Response::json(true, "Профиль обновлен!", 'success', ['reload' => true]);
        } catch (\Throwable $e) {
            Response::caught($e);
        }
    }

    /** Пользовательская настройка по whitelist ключей (user). */
    public static function saveOption(): void {
        $key = $_POST['key'] ?? '';
        $value = $_POST['value'] ?? '';

        $allowedKeys = ['chat_title_enabled'];
        if (!in_array($key, $allowedKeys)) {
            Response::json(false, "Некорректная настройка", 'error');
        }

        if ((new UserManager())->setUserOption(Auth::userId(), $key, $value)) {
            Response::json(true, "Saved");
        }
        Response::json(false, "DB Error", 'error');
    }

    /** Список соц-привязок текущего пользователя (user). */
    public static function getSocials(): void {
        $socials = (new UserManager())->getUserSocials(Auth::userId());
        Response::json(true, "Список соцсетей получен", 'success', ['socials' => $socials]);
    }

    /** Отвязка соцсети (user). */
    public static function unlinkSocial(): void {
        $provider = $_POST['provider'] ?? '';
        if (empty($provider)) Response::json(false, "Провайдер не указан", 'error');

        if ((new UserManager())->unlinkSocial(Auth::userId(), $provider)) {
            Response::json(true, "Аккаунт отвязан!");
        }
        Response::json(false, "Привязка не найдена.", 'error');
    }
}
