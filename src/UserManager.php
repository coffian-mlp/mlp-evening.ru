<?php

require_once __DIR__ . '/Database.php';

class UserManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAllUsers() {
        $result = $this->db->query("SELECT id, login, role, created_at FROM users ORDER BY id ASC");
        $users = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }
        return $users;
    }

    public function getUserById($id) {
        $stmt = $this->db->prepare("SELECT id, login, role FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    public function createUser($login, $password, $role = 'user') {
        // Проверка на уникальность логина
        $stmt = $this->db->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("Пользователь с таким логином уже существует.");
        }
        $stmt->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("INSERT INTO users (login, password_hash, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $login, $hash, $role);
        
        if ($stmt->execute()) {
            return $stmt->insert_id;
        } else {
            throw new Exception("Ошибка при создании пользователя: " . $stmt->error);
        }
    }

    public function updateUser($id, $login, $role, $password = null) {
        // Проверка на уникальность логина (исключая текущего юзера)
        $stmt = $this->db->prepare("SELECT id FROM users WHERE login = ? AND id != ?");
        $stmt->bind_param("si", $login, $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("Этот логин уже занят.");
        }
        $stmt->close();

        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE users SET login = ?, role = ?, password_hash = ? WHERE id = ?");
            $stmt->bind_param("sssi", $login, $role, $hash, $id);
        } else {
            $stmt = $this->db->prepare("UPDATE users SET login = ?, role = ? WHERE id = ?");
            $stmt->bind_param("ssi", $login, $role, $id);
        }

        if (!$stmt->execute()) {
             throw new Exception("Ошибка при обновлении: " . $stmt->error);
        }
        return true;
    }

    public function deleteUser($id) {
        // Защита от удаления последнего админа (опционально, но полезно)
        // Пока просто удаляем
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
}

