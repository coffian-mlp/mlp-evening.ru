<?php

namespace Api;

use Domain\Auth;
use Domain\CaptchaManager;
use Domain\UserManager;
use Infra\Mailer;
use LLM\BotDispatch;
use Social\SocialAuthService;
use Social\TelegramProvider;

/**
 * Обработчики auth-действий (MLP-264, срез AR5-6) — перенос из if-цепочки
 * api.php вербатим. Ответы — Api\Response; роль public/user проверяет роутер
 * (публичные экшены числятся в guest-whitelist api.php). CSRF — глобальный гейт.
 */
class AuthController {

    /** Вход по логину/паролю: brute-force-гейт, капча, remember-me (public). */
    public static function login(): void {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        // --- Brute Force Protection ---
        $ip = Auth::getIp();
        $status = Auth::checkLoginAttempts($ip);

        if ($status === 'blocked') {
            Response::json(false, "Слишком много неудачных попыток. Доступ закрыт на 24 часа. Отдохни и попей какао.", 'error');
        }

        if ($status === 'captcha_needed') {
            $captcha = new CaptchaManager();
            if (!$captcha->isCompleted()) {
                // Спец-код ошибки для JS (показ капчи).
                echo json_encode([
                    'success' => false,
                    'message' => 'Требуется проверка на робота (или чейнджлинга).',
                    'type' => 'error',
                    'error_code' => 'captcha_required'
                ]);
                exit();
            }
        }

        if (Auth::login($username, $password)) {
            Auth::resetLoginAttempts($ip);

            // remember-me: долгоживущий токен по галке (MLP-223)
            if (!empty($_POST['remember'])) {
                Auth::issueRememberToken(Auth::userId());
            }

            self::finishThenGreet(json_encode([
                'success' => true,
                'message' => "Добро пожаловать, $username! Рады тебя видеть!",
                'type' => 'success',
                'data' => ['reload' => true]
            ]), $username);
        }

        $newCount = Auth::recordFailedLogin($ip);
        // На порогах сбрасываем капчу — форсируем повторную проверку.
        if ($newCount === 3 || $newCount === 6) {
            (new CaptchaManager())->reset();
        }
        Response::json(false, "Упс! Неверное имя или пароль.", 'error');
    }

    /** Выход (user). */
    public static function logout(): void {
        Auth::logout();
        Response::json(true, "До скорой встречи!", 'success', ['reload' => true]);
    }

    /** Регистрация: капча, валидация, автовход (public). */
    public static function register(): void {
        $login = trim($_POST['login'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $captcha = new CaptchaManager();
        if (!$captcha->isCompleted()) {
            Response::json(false, "Сначала нужно пройти испытание Гармонии!", 'error');
        }

        if (mb_strlen($login) < 3) Response::json(false, "Логин слишком короткий (нужно хотя бы 3 символа)", 'error');
        if ($pwErr = Auth::validatePasswordPolicy($password)) Response::json(false, $pwErr, 'error');
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(false, "Некорректный формат Email", 'error');
        }

        try {
            (new UserManager())->createUser($login, $password, 'user', $nickname, $email);

            if (Auth::login($login, $password)) {
                self::finishThenGreet(json_encode([
                    'success' => true,
                    'message' => "Ура! Ты с нами! Добро пожаловать!",
                    'type' => 'success',
                    'data' => ['reload' => true]
                ]), $login);
            }
            Response::json(true, "Ура! Ты с нами! Теперь можно войти.", 'success');
        } catch (\Throwable $e) {
            Response::caught($e);
        }
    }

    /** Запрос сброса пароля: токен + письмо (public). Существование email не раскрываем. */
    public static function forgotPassword(): void {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(false, "Введите корректный Email", 'error');
        }

        $userManager = new UserManager();
        $user = $userManager->getUserByEmail($email);

        if (!$user) {
            // Security: не раскрываем существование пользователя.
            Response::json(true, "Если этот Email есть в базе, мы отправили письмо!");
        }

        try {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires = gmdate('Y-m-d H:i:s', time() + 3600); // 1 час

            if ($userManager->savePasswordResetToken($user['id'], $tokenHash, $expires)) {
                $mailer = new Mailer();
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $link = $protocol . $_SERVER['HTTP_HOST'] . "/reset_password.php?token=" . $token;

                if ($mailer->sendPasswordReset($email, $link)) {
                    Response::json(true, "Письмо отправлено на $email! Проверь папку Спам, если не придет.");
                }
                Response::json(false, "Ошибка отправки письма. Попробуйте позже.", 'error');
            }
            Response::json(false, "Ошибка БД", 'error');
        } catch (\Throwable $e) {
            Response::caught($e);
        }
    }

    /** Смена пароля по токену из письма (public). */
    public static function resetPasswordSubmit(): void {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($token) || empty($password)) {
            Response::json(false, "Неверные данные", 'error');
        }
        if ($pwErr = Auth::validatePasswordPolicy($password)) {
            Response::json(false, $pwErr, 'error');
        }

        $userManager = new UserManager();
        $user = $userManager->getUserByResetToken(hash('sha256', $token));
        if (!$user) {
            Response::json(false, "Ссылка устарела или недействительна.", 'error');
        }

        try {
            $userManager->updateUser($user['id'], ['password' => $password]);
            $userManager->clearResetToken($user['id']);
            Response::json(true, "Пароль успешно изменен! Теперь можно войти.", 'success', ['redirect' => '/']);
        } catch (\Throwable $e) {
            Response::caught($e, "Смена пароля: ");
        }
    }

    /** Вход/регистрация через соцсеть (public; сейчас только Telegram). */
    public static function socialLogin(): void {
        $providerName = $_POST['provider'] ?? '';
        $data = $_POST['data'] ?? [];

        if ($providerName !== 'telegram') {
            Response::json(false, "Неизвестный провайдер авторизации", 'error');
        }

        $result = (new SocialAuthService())->handleLogin(new TelegramProvider(), $data);

        if ($result['success']) {
            self::finishThenGreet(json_encode([
                'success' => true,
                'message' => $result['message'],
                'type' => 'success',
                'data' => ['redirect' => $result['redirect']]
            ]), Auth::username() ?? 'Гость');
        }
        Response::json(false, $result['message'], 'error');
    }

    /** Привязка соцсети к текущему аккаунту (user). */
    public static function bindSocial(): void {
        $providerName = $_POST['provider'] ?? '';
        $data = $_POST['data'] ?? [];
        $userId = Auth::userId();

        if ($providerName !== 'telegram') {
            Response::json(false, "Неизвестный провайдер", 'error');
        }

        try {
            $tgUser = (new TelegramProvider())->validateCallback($data);
            if (!$tgUser) {
                Response::json(false, "Ошибка проверки подписи Telegram. Данные подделаны или устарели.", 'error');
            }

            // Занят ли этот Telegram ID другим пони — через владельца user_socials (AR6-8).
            if ((new SocialAuthService())->isUidBound('telegram', (string)$tgUser['id'])) {
                Response::json(false, "Этот аккаунт Telegram уже привязан к кому-то другому!", 'error');
            }

            if ((new UserManager())->linkSocial($userId, 'telegram', $tgUser)) {
                Response::json(true, "Связь установлена!");
            }
            Response::json(false, "Ошибка базы данных.", 'error');
        } catch (\Throwable $e) {
            Response::caught($e, "Ошибка провайдера: ");
        }
    }

    /**
     * Отдать ответ клиенту, отпустить соединение и уже ПОСЛЕ этого разбудить
     * Лиру-приветствие (перенос тройного fastcgi-блока login/register/social_login
     * вербатим, MLP-264). Клиент не ждёт LLM.
     */
    private static function finishThenGreet(string $responseJson, string $greetUsername): never {
        Response::finish($responseJson); // MLP-265: общий fastcgi-паттерн в Response
        BotDispatch::dispatch('greeting', ['username' => $greetUsername]);
        exit();
    }
}
