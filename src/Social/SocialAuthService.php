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
            $this->updateSocialInfo($linkedAccount['id'], $socialUser);

            return ['success' => true, 'message' => 'Вход выполнен!', 'redirect' => '/'];
        }

        // Сценарий 2: Аккаунт НЕ привязан
        
        // А. Пользователь уже залогинен -> Привязываем к текущему
        if (Auth::check()) {
            $this->linkAccount($_SESSION['user_id'], $providerName, $socialUser);
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

    private function linkAccount($userId, $provider, $data) {
        $stmt = $this->db->prepare("
            INSERT INTO user_socials (user_id, provider, provider_uid, username, first_name, last_name, avatar_url)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssss", 
            $userId, 
            $provider, 
            $data['id'], 
            $data['username'], 
            $data['first_name'], 
            $data['last_name'], 
            $data['photo_url']
        );
        $stmt->execute();
    }

    private function updateSocialInfo($id, $data) {
        $stmt = $this->db->prepare("
            UPDATE user_socials 
            SET username = ?, first_name = ?, last_name = ?, avatar_url = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssi", 
            $data['username'], 
            $data['first_name'], 
            $data['last_name'], 
            $data['photo_url'],
            $id
        );
        $stmt->execute();
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
        $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

        // Создаем пользователя в основной таблице
        $stmt = $this->db->prepare("INSERT INTO users (login, nickname, password_hash, role) VALUES (?, ?, ?, 'user')");
        // Nickname берем красивый (First Name), а login - технический уникальный
        $nickname = $data['first_name'] ?: $login;
        $stmt->bind_param("sss", $login, $nickname, $passwordHash);
        
        if (!$stmt->execute()) {
            throw new Exception("Не удалось создать пользователя: " . $this->db->error);
        }

        $newUserId = $this->db->insert_id;

        // Если есть аватарка, сохраняем её в user_options (или профиль)
        // В текущей реализации аватарка хранится в user_options['avatar_url'] или users
        // UserManager->updateUser умеет распределять это.
        if (!empty($data['photo_url'])) {
            $this->userManager->updateUser($newUserId, ['avatar_url' => $data['photo_url']]);
        }

        // Привязываем соцсеть
        $this->linkAccount($newUserId, $provider, $data);

        return $newUserId;
    }
}
