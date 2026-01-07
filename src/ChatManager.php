<?php

require_once __DIR__ . '/Database.php';

class ChatManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function checkRateLimit($userId, $limitSeconds) {
        if ($limitSeconds <= 0) return true; // Ограничение отключено

        $stmt = $this->db->prepare("SELECT created_at FROM chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res && $row = $res->fetch_assoc()) {
            $lastTime = strtotime($row['created_at']);
            $currentTime = time();
            if (($currentTime - $lastTime) < $limitSeconds) {
                return false; // Слишком быстро!
            }
        }
        return true;
    }

    public function addMessage($userId, $username, $message) {
        $message = trim($message);
        if (empty($message)) {
            return false;
        }
        
        // Basic HTML escaping to prevent XSS
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $stmt = $this->db->prepare("INSERT INTO chat_messages (user_id, username, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $userId, $username, $message);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public function getMessages($limit = 50) {
        $limit = (int)$limit;
        // Join with users to get current color and avatar
        $query = "SELECT cm.*, u.chat_color, u.avatar_url 
                  FROM chat_messages cm 
                  LEFT JOIN users u ON cm.user_id = u.id 
                  ORDER BY cm.created_at DESC LIMIT $limit";
        
        $result = $this->db->query($query);
        
        $messages = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $messages[] = $row;
            }
        }
        return array_reverse($messages); 
    }

    public function getMessagesAfter($lastId) {
        $lastId = (int)$lastId;
        $stmt = $this->db->prepare("
            SELECT cm.*, u.chat_color, u.avatar_url 
            FROM chat_messages cm 
            LEFT JOIN users u ON cm.user_id = u.id 
            WHERE cm.id > ? 
            ORDER BY cm.id ASC
        ");
        $stmt->bind_param("i", $lastId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $messages = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $messages[] = $row;
            }
        }
        return $messages;
    }
}

