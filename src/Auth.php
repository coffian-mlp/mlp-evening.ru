<?php


class Auth {
    /**
     * Единая точка старта сессии с безопасными cookie-параметрами (MLP-222, M1).
     * HttpOnly + SameSite=Lax всегда; Secure — только на HTTPS (чтобы не сломать локальный HTTP).
     */
    private static function ensureSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => self::isSecureRequest(),
            ]);
            session_start();
        }
    }

    private static function isSecureRequest() {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
    }

    /** Смена ID сессии после аутентификации — защита от session fixation (MLP-222, M1). */
    public static function regenerateSession() {
        self::ensureSession();
        session_regenerate_id(true);
    }

    public static function check() {
        self::ensureSession();
        return isset($_SESSION['user_id']);
    }

    public static function requireLogin() {
        if (!self::check()) {
            header('Location: /');
            exit();
        }
    }

    public static function isAdmin() {
        if (!self::check()) return false;
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    public static function isModerator() {
        if (!self::check()) return false;
        $role = $_SESSION['role'] ?? '';
        return $role === 'admin' || $role === 'moderator';
    }

    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            require __DIR__ . '/../403.php'; 
            exit();
        }
    }
    
    public static function requireApiLogin() {
        if (!self::check()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized', 'type' => 'error']);
            exit();
        }
    }

    /** Гейт админского API-действия (AR-1, MLP-226). Заменяет копипаст isAdmin-проверок. */
    public static function requireApiAdmin() {
        if (!self::isAdmin()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access Denied', 'type' => 'error']);
            exit();
        }
    }

    public static function login($login, $password) {
        self::ensureSession();

        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT id, login, nickname, password_hash, role FROM users WHERE login = ?");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $row = $res->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
                session_regenerate_id(true); // M1: защита от session fixation
                $_SESSION['user_id'] = $row['id'];
                // Теперь используем nickname для отображения!
                $_SESSION['username'] = !empty($row['nickname']) ? $row['nickname'] : $row['login'];
                $_SESSION['role'] = $row['role'];
                
                self::generateCsrfToken();
                
                return true;
            }
        }
        
        return false;
    }

    public static function logout() {
        self::ensureSession();
        // remember-me: удаляем токен и cookie (MLP-223)
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            $selector = explode(':', $_COOKIE[self::REMEMBER_COOKIE], 2)[0];
            if ($selector) self::deleteRememberToken($selector);
            self::clearRememberCookie();
        }
        session_destroy();
    }

    // --- Remember-me (persistent token, MLP-223) ---
    const REMEMBER_COOKIE = 'mlp_remember';
    const REMEMBER_TTL = 2592000; // 30 дней

    /** Выдать remember-токен: строка в auth_tokens + cookie "<selector>:<validator>". */
    public static function issueRememberToken($userId) {
        $selector  = bin2hex(random_bytes(12));   // 24 hex
        $validator = bin2hex(random_bytes(32));   // 64 hex
        $hash      = hash('sha256', $validator);
        $expires   = gmdate('Y-m-d H:i:s', time() + self::REMEMBER_TTL);

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("INSERT INTO auth_tokens (selector, validator_hash, user_id, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $selector, $hash, $userId, $expires);
        $stmt->execute();

        self::setRememberCookie($selector . ':' . $validator, time() + self::REMEMBER_TTL);
    }

    /** Авто-вход по remember-cookie, если активной сессии нет. Ротирует токен (sliding expiry). */
    public static function tryRememberLogin() {
        if (self::check()) return;                       // уже вошёл
        if (empty($_COOKIE[self::REMEMBER_COOKIE])) return;

        $parts = explode(':', $_COOKIE[self::REMEMBER_COOKIE], 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            self::clearRememberCookie();
            return;
        }
        list($selector, $validator) = $parts;

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT validator_hash, user_id, expires_at FROM auth_tokens WHERE selector = ?");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) { self::clearRememberCookie(); return; }
        if (strtotime($row['expires_at'] . ' UTC') < time()) {
            self::deleteRememberToken($selector); self::clearRememberCookie(); return;
        }
        if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
            // невалидный validator при валидном selector — токен скомпрометирован/устарел
            self::deleteRememberToken($selector); self::clearRememberCookie(); return;
        }

        $user = self::fetchUserForSession((int)$row['user_id']);
        if (!$user) { self::deleteRememberToken($selector); self::clearRememberCookie(); return; }

        // Устанавливаем сессию (с регенерацией id).
        self::regenerateSession();
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = !empty($user['nickname']) ? $user['nickname'] : $user['login'];
        $_SESSION['role']     = $user['role'];
        self::generateCsrfToken();

        // Ротация: старый токен недействителен, выдаём новый с продлённым сроком.
        self::deleteRememberToken($selector);
        self::issueRememberToken($user['id']);
    }

    private static function fetchUserForSession($userId) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, login, nickname, role, is_banned FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        if (!$u || $u['is_banned']) return null; // забаненных не авто-логиним
        return $u;
    }

    private static function deleteRememberToken($selector) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM auth_tokens WHERE selector = ?");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
    }

    private static function setRememberCookie($value, $expires) {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isSecureRequest(),
        ]);
        $_COOKIE[self::REMEMBER_COOKIE] = $value;
    }

    private static function clearRememberCookie() {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => self::isSecureRequest(),
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    public static function generateCsrfToken() {
        self::ensureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function checkCsrfToken($token) {
        self::ensureSession();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // --- Password Policy (L5) ---

    /** Минимум символов в пароле. */
    const PASSWORD_MIN = 8;
    /** Максимум в БАЙТАХ: bcrypt молча режет всё после 72 байт — не даём завести такой пароль. */
    const PASSWORD_MAX_BYTES = 72;

    /**
     * Единая проверка политики пароля (L5).
     * Возвращает текст ошибки для пользователя или null, если пароль валиден.
     * Сложность (классы символов) намеренно не требуем — по современным
     * рекомендациям длина важнее навязанной сложности.
     */
    public static function validatePasswordPolicy($password): ?string {
        if (!is_string($password) || $password === '') {
            return "Введите пароль";
        }
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            return "Пароль слишком короткий (нужно хотя бы " . self::PASSWORD_MIN . " символов)";
        }
        if (strlen($password) > self::PASSWORD_MAX_BYTES) {
            return "Пароль слишком длинный (максимум " . self::PASSWORD_MAX_BYTES . " байт)";
        }
        return null;
    }

    // --- Brute Force Protection ---

    public static function getIp() {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function checkLoginAttempts($ip) {
        $db = Database::getInstance()->getConnection();
        
        // Cleanup old blocked entries (optional, but good for hygiene)
        // Or we just check timestamps.
        
        $stmt = $db->prepare("SELECT attempts_count, last_attempt_at, blocked_until FROM login_attempts WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        
        if (!$row) return 'ok'; // No record
        
        // 1. Check if blocked
        if (!empty($row['blocked_until'])) {
            $blockedUntil = strtotime($row['blocked_until'] . ' UTC');
            if (time() < $blockedUntil) {
                return 'blocked';
            }
        }
        
        // 2. Check thresholds
        $count = $row['attempts_count'];
        
        if ($count >= 9) return 'blocked'; // Should have been set in blocked_until, but double check
        
        if ($count >= 3 && $count < 6) return 'captcha_needed';
        if ($count >= 6) return 'captcha_needed';
        
        return 'ok';
    }

    public static function recordFailedLogin($ip) {
        $db = Database::getInstance()->getConnection();
        
        // Insert or Update
        // MySQL ON DUPLICATE KEY UPDATE increments count
        // Also check logic for 9th attempt
        
        // First get current state to decide on blocking
        $stmt = $db->prepare("SELECT attempts_count FROM login_attempts WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        
        $newCount = ($row['attempts_count'] ?? 0) + 1;
        $blockedUntil = null;
        
        if ($newCount >= 9) {
            $blockedUntil = gmdate('Y-m-d H:i:s', time() + 86400); // 24 hours
        }
        
        $now = gmdate('Y-m-d H:i:s');
        
        $sql = "INSERT INTO login_attempts (ip_address, attempts_count, last_attempt_at, blocked_until) 
                VALUES (?, 1, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                attempts_count = attempts_count + 1, 
                last_attempt_at = VALUES(last_attempt_at),
                blocked_until = VALUES(blocked_until)";
                
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sss", $ip, $now, $blockedUntil);
        $stmt->execute();
        
        return $newCount;
    }

    public static function resetLoginAttempts($ip) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
    }
}