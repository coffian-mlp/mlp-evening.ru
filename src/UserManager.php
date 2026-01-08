<?php

require_once __DIR__ . '/Database.php';

class UserManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAllUsers() {
        // Fetch moderation status too
        $result = $this->db->query("SELECT id, login, nickname, role, created_at, avatar_url, chat_color, is_banned, muted_until, ban_reason FROM users ORDER BY id ASC");
        $users = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // Determine active status
                $row['is_muted'] = false;
                if (!empty($row['muted_until'])) {
                    $muteTime = strtotime($row['muted_until'] . ' UTC');
                    if ($muteTime > time()) {
                        $row['is_muted'] = true;
                    }
                }
                $users[] = $row;
            }
        }
        return $users;
    }

    public function getUserById($id) {
        $stmt = $this->db->prepare("SELECT id, login, nickname, role, avatar_url, chat_color FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    // Универсальный метод обновления
    public function updateUser($id, $data) {
        $updates = [];
        $types = "";
        $params = [];

        if (isset($data['nickname'])) {
            $updates[] = "nickname = ?";
            $types .= "s";
            $params[] = $data['nickname'];
        }

        if (isset($data['chat_color'])) {
            $updates[] = "chat_color = ?";
            $types .= "s";
            $params[] = $data['chat_color'];
        }

        if (isset($data['avatar_url'])) {
            $updates[] = "avatar_url = ?";
            $types .= "s";
            $params[] = $data['avatar_url'];
        }

        if (isset($data['role'])) {
            $updates[] = "role = ?";
            $types .= "s";
            $params[] = $data['role'];
        }

        if (isset($data['login'])) {
            // Check uniqueness
            $stmt = $this->db->prepare("SELECT id FROM users WHERE login = ? AND id != ?");
            $stmt->bind_param("si", $data['login'], $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                throw new Exception("Логин уже занят.");
            }
            $stmt->close();

            $updates[] = "login = ?";
            $types .= "s";
            $params[] = $data['login'];
        }

        if (isset($data['password']) && !empty($data['password'])) {
            $updates[] = "password_hash = ?";
            $types .= "s";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (empty($updates)) return true; 

        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
        $types .= "i";
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if (!$stmt->execute()) {
            throw new Exception("Ошибка обновления: " . $stmt->error);
        }
        return true;
    }

    // Алиас для профиля
    public function updateProfile($userId, $data) {
        unset($data['role']); 
        return $this->updateUser($userId, $data);
    }

    public function createUser($login, $password, $role = 'user', $nickname = null) {
        if (empty($nickname)) $nickname = $login; 

        $stmt = $this->db->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("Пользователь с таким логином уже существует.");
        }
        $stmt->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("INSERT INTO users (login, nickname, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $login, $nickname, $hash, $role);
        
        if ($stmt->execute()) {
            return $stmt->insert_id;
        } else {
            throw new Exception("Ошибка при создании пользователя: " . $stmt->error);
        }
    }

    public function deleteUser($id) {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    // --- Options Methods (New) ---

    public function setUserOption($userId, $key, $value) {
        // ON DUPLICATE KEY UPDATE logic
        $sql = "INSERT INTO user_options (user_id, option_key, option_value) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("iss", $userId, $key, $value);
        return $stmt->execute();
    }

    public function getUserOptions($userId, $optionKey = null) {
        if ($optionKey) {
            $stmt = $this->db->prepare("SELECT option_value FROM user_options WHERE user_id = ? AND option_key = ?");
            $stmt->bind_param("is", $userId, $optionKey);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                return $row['option_value'];
            }
            return null;
        }

        $stmt = $this->db->prepare("SELECT option_key, option_value FROM user_options WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $options = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $options[$row['option_key']] = $row['option_value'];
            }
        }
        return $options;
    }

    // --- Moderation Methods ---

    public function getBanStatus($userId) {
        $stmt = $this->db->prepare("SELECT is_banned, muted_until, ban_reason FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    public function banUser($userId, $reason = null, $moderatorId = null) {
        $stmt = $this->db->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE id = ?");
        $stmt->bind_param("si", $reason, $userId);
        $res = $stmt->execute();
        
        if ($res && $moderatorId) {
             $this->logAction($moderatorId, 'ban', $userId, "Reason: $reason");
        }
        return $res;
    }

    public function unbanUser($userId, $moderatorId = null) {
        $stmt = $this->db->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $res = $stmt->execute();

        if ($res && $moderatorId) {
            $this->logAction($moderatorId, 'unban', $userId);
        }
        return $res;
    }

    public function muteUser($userId, $minutes, $moderatorId = null, $reason = null) {
        // Calculate UTC time in PHP to avoid SQL syntax issues with INTERVAL binding
        $muteUntil = gmdate('Y-m-d H:i:s', time() + ($minutes * 60));
        
        // Also ensure is_banned is 0, so it displays as Muted, not Banned
        $stmt = $this->db->prepare("UPDATE users SET muted_until = ?, ban_reason = ?, is_banned = 0 WHERE id = ?");
        $stmt->bind_param("ssi", $muteUntil, $reason, $userId);
        $res = $stmt->execute();

        if ($res && $moderatorId) {
            $this->logAction($moderatorId, 'mute', $userId, "Duration: $minutes min. Reason: $reason");
        }
        return $res;
    }

    public function unmuteUser($userId, $moderatorId = null) {
        $stmt = $this->db->prepare("UPDATE users SET muted_until = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $res = $stmt->execute();

        if ($res && $moderatorId) {
            $this->logAction($moderatorId, 'unmute', $userId);
        }
        return $res;
    }

    public function logAction($moderatorId, $action, $targetId, $details = null) {
        $stmt = $this->db->prepare("INSERT INTO audit_logs (user_id, action, target_id, details) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isis", $moderatorId, $action, $targetId, $details);
        return $stmt->execute();
    }

    public function getAuditLogs($limit = 100) {
        $query = "SELECT al.*, 
                         u_mod.login as mod_login, u_mod.nickname as mod_nickname,
                         u_target.login as target_login, u_target.nickname as target_nickname
                  FROM audit_logs al
                  LEFT JOIN users u_mod ON al.user_id = u_mod.id
                  LEFT JOIN users u_target ON al.target_id = u_target.id
                  ORDER BY al.created_at DESC LIMIT ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $logs = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        return $logs;
    }
}
