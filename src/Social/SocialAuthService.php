<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../UserManager.php';
require_once __DIR__ . '/SocialProvider.php';

class SocialAuthService {
    private $db;
    private $userManager;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->userManager = new UserManager();
    }

    /**
     * Обрабатывает вход через соцсеть
     * @param SocialProvider $provider Провайдер (Telegram, etc)
     * @param array $rawData Сырые данные из запроса ($_GET/$_POST)
     * @return array Результат ['success' => bool, 'message' => string, 'redirect' => string]
     */
    public function handleLogin(SocialProvider $provider, array $rawData): array {
        // 1. Валидация данных провайдером
        $socialUser = $provider->validateCallback($rawData);
        
        if (!$socialUser) {
            return ['success' => false, 'message' => 'Ошибка проверки подписи данных (Invalid Signature)'];
        }

        $providerName = $provider->getName();
        $providerUid = $socialUser['id'];

        // 2. Ищем привязку в БД
        $stmt = $this->db->prepare("SELECT id, user_id FROM user_socials WHERE provider = ? AND provider_uid = ? LIMIT 1");
        $stmt->bind_param("ss", $providerName, $providerUid);
        $stmt->execute();
        $result = $stmt->get_result();
        $linkedAccount = $result->fetch_assoc();

        // Сценарий 1: Аккаунт уже привязан
        if ($linkedAccount) {
            $userId = $linkedAccount['user_id'];
            
            // Если пользователь сейчас залогинен, и это НЕ его аккаунт -> Ошибка (уже занято другим)
            if (Auth::check() && $_SESSION['user_id'] != $userId) {
                return ['success' => false, 'message' => 'Этот аккаунт соцсети уже привязан к другому пользователю!'];
            }

            // Логиним пользователя (если не залогинен)
            if (!Auth::check()) {
                // Проверяем бан и существование юзера
                $user = $this->userManager->getUserById($userId);
                if (!$user) {
                     // Сирота в user_socials? Удалим связь.
                     $this->db->query("DELETE FROM user_socials WHERE id = " . $linkedAccount['id']);
                     return ['success' => false, 'message' => 'Связанный пользователь не найден. Попробуйте снова.'];
                }
                
                if ($user['is_banned']) {
                     return ['success' => false, 'message' => 'Вы забанены: ' . ($user['ban_reason'] ?? 'Нарушение правил')];
                }

                // Вход
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['login'];
                $_SESSION['role'] = $user['role'];
            }
            
            // Обновляем инфо в user_socials (вдруг сменил ник/аву)
            // Используем метод UserManager для сброса кеша
            $this->userManager->updateSocialInfo($linkedAccount['id'], $socialUser);

            return ['success' => true, 'message' => 'Вход выполнен!', 'redirect' => '/'];
        }

        // Сценарий 2: Аккаунт НЕ привязан
        
        // А. Пользователь уже залогинен -> Привязываем к текущему
        if (Auth::check()) {
            $this->userManager->linkSocial($_SESSION['user_id'], $providerName, $socialUser);
            return ['success' => true, 'message' => 'Аккаунт успешно привязан!', 'redirect' => '/dashboard.php#tab-profile']; // Предполагаемый редирект
        }

        // Б. Новый пользователь (Регистрация)
        try {
            $newUserId = $this->registerNewUser($providerName, $socialUser);
            
            // Сразу логиним
            $user = $this->userManager->getUserById($newUserId);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['login'];
            $_SESSION['role'] = $user['role'];
            
            return ['success' => true, 'message' => 'Добро пожаловать, новый пони!', 'redirect' => '/'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Ошибка регистрации: ' . $e->getMessage()];
        }
    }

    private function registerNewUser($provider, $data): int {
        // Генерируем логин
        // Попытка 1: Используем username из соцсети
        $baseLogin = $data['username'] ?: $data['first_name'];
        // Очищаем от спецсимволов, оставляем только буквы/цифры
        $baseLogin = preg_replace('/[^a-zA-Z0-9]/', '', $baseLogin);
        
        if (empty($baseLogin)) {
            $baseLogin = 'Pony';
        }

        $login = $baseLogin;
        $counter = 1;

        // Проверяем уникальность логина
        while ($this->userManager->getUserByLogin($login)) {
            $login = $baseLogin . $counter;
            $counter++;
        }

        // Генерируем надежный случайный пароль (пользователь его не знает, но он нужен базе)
        $randomPassword = bin2hex(random_bytes(16));

        // Создаем пользователя через UserManager (он же и кеш сбросит)
        $nickname = $data['first_name'] ?: $login;
        
        $newUserId = $this->userManager->createUser($login, $randomPassword, 'user', $nickname);

        // Если есть аватарка, сохраняем её в user_options (или профиль)
        if (!empty($data['photo_url'])) {
            $this->userManager->updateUser($newUserId, ['avatar_url' => $data['photo_url']]);
        }

        // Привязываем соцсеть
        $this->userManager->linkSocial($newUserId, $provider, $data);

        return $newUserId;
    }
}
