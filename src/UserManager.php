<?php

require_once __DIR__ . '/Database.php';

class UserManager {
    private $db;
    private $cacheDir;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->cacheDir = __DIR__ . '/../cache/users/';
    }

    // --- Caching Helpers ---

    private function getCache($key, $ttl = 2592000) { // Default 30 days
        $file = $this->cacheDir . $key . '.json';
        if (file_exists($file)) {
            if (time() - filemtime($file) < $ttl) {
                $content = file_get_contents($file);
                return $content ? json_decode($content, true) : null;
            }
        }
        return null;
    }

    private function setCache($key, $data) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
        file_put_contents($this->cacheDir . $key . '.json', json_encode($data));
    }

    private function clearCache($key) {
         $file = $this->cacheDir . $key . '.json';
         if (file_exists($file)) unlink($file);
    }

    private function clearAllUsersCache() {
        $this->clearCache('all_users');
    }

    private function clearUserCache($userId) {
        $this->clearCache('options_' . $userId);
        $this->clearCache('socials_' . $userId);
        $this->clearAllUsersCache(); // Changing user might affect the global list
    }

    // --- Main Methods ---

    public function getAllUsers() {
        $cached = $this->getCache('all_users', 300); // 5 minutes TTL for list
        if ($cached) return $cached;

        // Fetch users with options via JOINs
        $sql = "SELECT u.id, u.login, u.nickname, u.email, u.role, u.created_at, u.is_banned, u.muted_until, u.ban_reason,
                       uo_color.option_value as chat_color,
                       uo_avatar.option_value as avatar_url
                FROM users u
                LEFT JOIN user_options uo_color ON u.id = uo_color.user_id AND uo_color.option_key = 'chat_color'
                LEFT JOIN user_options uo_avatar ON u.id = uo_avatar.user_id AND uo_avatar.option_key = 'avatar_url'
                ORDER BY u.id ASC";
                
        $result = $this->db->query($sql);
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
                // Set defaults if null
                if (empty($row['chat_color'])) $row['chat_color'] = '#6d2f8e';
                
                $users[] = $row;
            }
        }

        $this->setCache('all_users', $users);
        return $users;
    }

    public function getUserById($id) {
        // Keeping live DB call for critical auth/session checks
        // But we could add short cache here if needed.
        $stmt = $this->db->prepare("
            SELECT u.id, u.login, u.nickname, u.email, u.role, u.is_banned, u.ban_reason,
                   uo_color.option_value as chat_color,
                   uo_avatar.option_value as avatar_url
            FROM users u
            LEFT JOIN user_options uo_color ON u.id = uo_color.user_id AND uo_color.option_key = 'chat_color'
            LEFT JOIN user_options uo_avatar ON u.id = uo_avatar.user_id AND uo_avatar.option_key = 'avatar_url'
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        
        if ($user && empty($user['chat_color'])) {
            $user['chat_color'] = '#6d2f8e';
        }
        
        return $user;
    }

    public function getUserByLogin($login) {
        $stmt = $this->db->prepare("SELECT id, login, nickname, email, role, password_hash, is_banned, ban_reason FROM users WHERE login = ?");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    public function getUserByEmail($email) {
        $stmt = $this->db->prepare("SELECT id, login, nickname, email, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    // Универсальный метод обновления
    public function updateUser($id, $data) {
        $updates = [];
        $types = "";
        $params = [];

        // 1. Separate options from main table fields
        $optionFields = ['chat_color', 'avatar_url', 'font_preference', 'font_scale'];

        
        foreach ($optionFields as $field) {
            if (isset($data[$field])) {
                $this->setUserOption($id, $field, $data[$field]);
                // Remove from data to avoid SQL error in main update
                unset($data[$field]); 
            }
        }

        // 2. Update main table
        if (isset($data['nickname'])) {
            $updates[] = "nickname = ?";
            $types .= "s";
            $params[] = $data['nickname'];
        }

        if (isset($data['email'])) {
            // Check uniqueness
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $data['email'], $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                throw new Exception("Этот Email уже используется другой пони.");
            }
            $stmt->close();

            $updates[] = "email = ?";
            $types .= "s";
            $params[] = $data['email'];
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

        $success = true;
        if (!empty($updates)) {
            $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
            $types .= "i";
            $params[] = $id;

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception("Ошибка обновления: " . $stmt->error);
            }
            $success = true;
        }

        if ($success) {
            $this->clearUserCache($id);
        }
        return $success;
    }

    // Алиас для профиля
    public function updateProfile($userId, $data) {
        unset($data['role']); 
        return $this->updateUser($userId, $data);
    }

    public function createUser($login, $password, $role = 'user', $nickname = null, $email = null) {
        if (empty($nickname)) $nickname = $login; 
        
        // ... (check login) ...
        $stmt = $this->db->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("Пользователь с таким логином уже существует.");
        }
        $stmt->close();

        if (!empty($email)) {
             $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
             $stmt->bind_param("s", $email);
             $stmt->execute();
             if ($stmt->get_result()->num_rows > 0) {
                 throw new Exception("Пользователь с таким Email уже существует.");
             }
             $stmt->close();
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (login, nickname, password_hash, role, email) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("sssss", $login, $nickname, $hash, $role, $email);
        
        if ($stmt->execute()) {
            $newUserId = $stmt->insert_id;
            
            // Random Color Generation 🎨
            $colors = [
                '#6d2f8e', // Twilight (Default)
                '#D63E85', // Pinkie
                '#D67229', // Applejack
                '#0087BD', // Rainbow Dash
                '#5B2C6F', // Rarity
                '#C7971E', // Fluttershy (Gold)
                '#2E8B57', // Lyra
                '#1E3F5A', // Bon Bon
                '#D32F2F', // Red
                '#C2185B', // Pink
                '#7B1FA2', // Purple
                '#512DA8', // Deep Purple
                '#303F9F', // Indigo
                '#1976D2', // Blue
                '#0288D1', // Light Blue
                '#0097A7', // Cyan
                '#00796B', // Teal
                '#388E3C', // Green
                '#689F38', // Light Green
                '#FBC02D', // Yellow
                '#FFA000', // Amber
                '#F57C00', // Orange
                '#E64A19', // Deep Orange
                '#5D4037', // Brown
                '#455A64'  // Blue Grey
            ];
            $randomColor = $colors[array_rand($colors)];

            // Set default options
            $this->setUserOption($newUserId, 'chat_color', $randomColor);
            $this->setUserOption($newUserId, 'avatar_url', '/assets/img/default-avatar.png');
            
            $this->clearAllUsersCache(); // Add to list
            return $newUserId;
        } else {
            throw new Exception("Ошибка при создании пользователя: " . $stmt->error);
        }
    }

    public function deleteUser($id) {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $this->clearUserCache($id);
            return true;
        }
        return false;
    }

    // --- Password Reset Methods ---

    public function savePasswordResetToken($userId, $tokenHash, $expiresAt) {
        $stmt = $this->db->prepare("UPDATE users SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?");
        $stmt->bind_param("ssi", $tokenHash, $expiresAt, $userId);
        return $stmt->execute();
    }

    public function getUserByResetToken($tokenHash) {
        // Check for token match AND not expired
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("SELECT id, login, email FROM users WHERE reset_token_hash = ? AND reset_token_expires > ?");
        $stmt->bind_param("ss", $tokenHash, $now);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    public function clearResetToken($userId) {
        $stmt = $this->db->prepare("UPDATE users SET reset_token_hash = NULL, reset_token_expires = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        return $stmt->execute();
    }

    // --- Options Methods ---

    public function setUserOption($userId, $key, $value) {
        // ON DUPLICATE KEY UPDATE logic
        $sql = "INSERT INTO user_options (user_id, option_key, option_value) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("iss", $userId, $key, $value);
        if ($stmt->execute()) {
            $this->clearUserCache($userId);
            return true;
        }
        return false;
    }

    public function getUserOptions($userId, $optionKey = null) {
        // Try cache for full options
        $cached = $this->getCache('options_' . $userId);
        
        if ($cached) {
            if ($optionKey) return $cached[$optionKey] ?? null;
            return $cached;
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
        
        $this->setCache('options_' . $userId, $options);
        
        if ($optionKey) return $options[$optionKey] ?? null;
        return $options;
    }

    // --- Socials Methods (New) ---

    public function getUserSocials($userId) {
        $cached = $this->getCache('socials_' . $userId);
        if ($cached) return $cached;

        $stmt = $this->db->prepare("SELECT id, provider, provider_uid, username, first_name, last_name, avatar_url FROM user_socials WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $socials = [];
        while ($row = $result->fetch_assoc()) {
            $socials[] = $row;
        }

        $this->setCache('socials_' . $userId, $socials);
        return $socials;
    }

    public function linkSocial($userId, $provider, $data) {
         // Using data format from SocialProvider logic
         // data: id, username, first_name, last_name, photo_url
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
        if ($stmt->execute()) {
            $this->clearCache('socials_' . $userId);
            return true;
        }
        return false;
    }

    public function unlinkSocial($userId, $provider) {
        $stmt = $this->db->prepare("DELETE FROM user_socials WHERE user_id = ? AND provider = ?");
        $stmt->bind_param("is", $userId, $provider);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $this->clearCache('socials_' . $userId);
            return true;
        }
        return false;
    }
    
    // For SocialAuthService usage mostly
    public function updateSocialInfo($id, $data) {
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
        if ($stmt->execute()) {
            // Need to find user_id for this social to clear cache
            // But this query doesn't have it.
            // Let's assume the caller knows or we fetch it.
            // Or just invalidate loosely?
            // Actually, updateSocialInfo is called after finding the linkedAccount which has user_id.
            // But here we only have ID of user_socials row.
            // We can fetch user_id first.
            $check = $this->db->prepare("SELECT user_id FROM user_socials WHERE id = ?");
            $check->bind_param("i", $id);
            $check->execute();
            $res = $check->get_result()->fetch_assoc();
            if ($res) {
                 $this->clearCache('socials_' . $res['user_id']);
            }
            return true;
        }
        return false;
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
        
        if ($res) {
             $this->clearUserCache($userId); // Banned status changed
             if ($moderatorId) {
                $this->logAction($moderatorId, 'ban', $userId, "Reason: $reason");
             }
        }
        return $res;
    }

    public function unbanUser($userId, $moderatorId = null) {
        $stmt = $this->db->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $res = $stmt->execute();

        if ($res) {
            $this->clearUserCache($userId);
            if ($moderatorId) {
                $this->logAction($moderatorId, 'unban', $userId);
            }
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

        if ($res) {
            $this->clearUserCache($userId);
            if ($moderatorId) {
                $this->logAction($moderatorId, 'mute', $userId, "Duration: $minutes min. Reason: $reason");
            }
        }
        return $res;
    }

    public function unmuteUser($userId, $moderatorId = null) {
        $stmt = $this->db->prepare("UPDATE users SET muted_until = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $res = $stmt->execute();

        if ($res) {
            $this->clearUserCache($userId);
            if ($moderatorId) {
                $this->logAction($moderatorId, 'unmute', $userId);
            }
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

    // --- Online Status Methods ---

    public function updateLastSeen($userId) {
        // Use UTC timestamp
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE users SET last_seen = ? WHERE id = ?");
        $stmt->bind_param("si", $now, $userId);
        // This is updated frequently, maybe we don't want to clear cache every time?
        // Last seen is not in getAllUsers result usually (except for logic?).
        // getAllUsers selects created_at, but not last_seen in my previous code.
        // Wait, getOnlineUsers uses it.
        // But getOnlineUsers is not cached yet. 
        // If we cache getOnlineUsers, we need to clear it here.
        // For now, let's leave it without clearing "all_users" cache, 
        // as "all_users" list doesn't show last_seen.
        return $stmt->execute();
    }

    public function getOnlineUsers($minutes = 5) {
        // Users active in last N minutes
        // We use UTC for comparison
        $threshold = gmdate('Y-m-d H:i:s', time() - ($minutes * 60));
        
        $sql = "SELECT u.id, u.nickname, uo_color.option_value as chat_color
                FROM users u
                LEFT JOIN user_options uo_color ON u.id = uo_color.user_id AND uo_color.option_key = 'chat_color'
                WHERE u.last_seen > ?
                ORDER BY u.nickname ASC";
                
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $threshold);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $users = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (empty($row['chat_color'])) $row['chat_color'] = '#6d2f8e';
                $users[] = $row;
            }
        }
        return $users;
    }
}
