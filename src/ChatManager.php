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

        // Используем UTC для хранения!
        $stmt = $this->db->prepare("INSERT INTO chat_messages (user_id, username, message, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())");
        $stmt->bind_param("iss", $userId, $username, $message);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public function editMessage($messageId, $userId, $newMessage) {
        $newMessage = trim($newMessage);
        if (empty($newMessage)) return false;

        $stmt = $this->db->prepare("SELECT user_id, created_at, is_deleted FROM chat_messages WHERE id = ?");
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
        $res = $stmt->get_result();

        if (!$res || !($row = $res->fetch_assoc())) {
            return false; // Сообщение не найдено
        }

        if ($row['is_deleted']) return false; // Нельзя редактировать удаленное

        if ($row['user_id'] != $userId) {
            return false; // Чужое сообщение
        }

        // Проверка времени (10 минут)
        $msgTime = strtotime($row['created_at']);
        // Сравниваем с UTC текущим временем, раз уж в базе UTC
        if ((time() - $msgTime) > 600) {
            return false; // Время вышло
        }

        $newMessage = htmlspecialchars($newMessage, ENT_QUOTES, 'UTF-8');
        
        $updateStmt = $this->db->prepare("UPDATE chat_messages SET message = ?, edited_at = UTC_TIMESTAMP() WHERE id = ?");
        $updateStmt->bind_param("si", $newMessage, $messageId);
        return $updateStmt->execute();
    }

    public function deleteMessage($messageId, $userId, $isAdmin = false) {
        $stmt = $this->db->prepare("SELECT user_id FROM chat_messages WHERE id = ?");
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
        $res = $stmt->get_result();

        if (!$res || !($row = $res->fetch_assoc())) {
            return false; 
        }

        if (!$isAdmin && $row['user_id'] != $userId) {
            return false; // Нет прав
        }

        // Удаление без проверки времени!
        $updateStmt = $this->db->prepare("UPDATE chat_messages SET is_deleted = 1 WHERE id = ?");
        $updateStmt->bind_param("i", $messageId);
        return $updateStmt->execute();
    }

    private function processMessages($messages) {
        foreach ($messages as &$msg) {
            if (!empty($msg['is_deleted'])) {
                $msg['message'] = '<em style="color:#999;">Сообщение удалено</em>';
                // Можно добавить флаг, чтобы фронтенд знал
                $msg['deleted'] = true;
            }
            // Форматируем дату в ISO 8601 UTC (добавляем Z)
            // Это скажет JS, что время в UTC
            if ($msg['created_at']) {
                $msg['created_at'] = date('Y-m-d\TH:i:s\Z', strtotime($msg['created_at']));
            }
            if ($msg['edited_at']) {
                $msg['edited_at'] = date('Y-m-d\TH:i:s\Z', strtotime($msg['edited_at']));
            } else {
                $msg['edited_at'] = null; // Явно null, если нет
            }
        }
        return $messages;
    }

    public function getMessages($limit = 50) {
        $limit = (int)$limit;
        // Join with users to get current color and avatar
        // Сортируем по ID DESC, чтобы получить последние $limit сообщений
        $query = "SELECT cm.*, u.chat_color, u.avatar_url 
                  FROM chat_messages cm 
                  LEFT JOIN users u ON cm.user_id = u.id 
                  ORDER BY cm.id DESC LIMIT $limit";
        
        $result = $this->db->query($query);
        
        $messages = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $messages[] = $row;
            }
        }
        // Переворачиваем массив, чтобы на клиенте старые были сверху, новые снизу
        return $this->processMessages(array_reverse($messages)); 
    }

    public function getMessagesAfter($lastId, $lastEditedTime = null) {
        $lastId = (int)$lastId;
        $params = [$lastId];
        $types = "i";
        
        $sql = "SELECT cm.*, u.chat_color, u.avatar_url 
                FROM chat_messages cm 
                LEFT JOIN users u ON cm.user_id = u.id 
                WHERE cm.id > ?";
        
        // Добавляем условие для измененных сообщений
        if ($lastEditedTime) {
            $sql .= " OR (cm.edited_at > ? AND cm.id <= ?)";
            // Для сравнения времени используем строку, так как edited_at это DATETIME/TIMESTAMP
            // А lastEditedTime мы ожидаем в формате 'Y-m-d H:i:s' UTC или timestamp
            $params[] = $lastEditedTime;
            $params[] = $lastId;
            $types .= "si";
        }
        
        $sql .= " ORDER BY cm.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $messages = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $messages[] = $row;
            }
        }
        return $this->processMessages($messages);
    }
}
