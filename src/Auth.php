<?php

require_once __DIR__ . '/Database.php';

class Auth {
    public static function check() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
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
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT id, login, nickname, password_hash, role FROM users WHERE login = ?");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $row = $res->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
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
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
    }

    public static function generateCsrfToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function checkCsrfToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}