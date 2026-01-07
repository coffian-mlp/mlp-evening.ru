<?php

require_once __DIR__ . '/Database.php';

class UserManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAllUsers() {
        $result = $this->db->query("SELECT id, login, nickname, role, created_at, avatar_url, chat_color FROM users ORDER BY id ASC");
        $users = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
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
}

