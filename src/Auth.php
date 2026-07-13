<?php

require_once __DIR__ . '/Database.php';

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
        session_destroy();
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