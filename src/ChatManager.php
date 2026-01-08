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

    public function addMessage($userId, $username, $message, $quotedMsgIds = []) {
        $message = trim($message);
        if (empty($message)) {
            return false;
        }
        
        // Basic HTML escaping to prevent XSS
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $quotedJson = null;
        if (!empty($quotedMsgIds) && is_array($quotedMsgIds)) {
            // Validate that all IDs are integers
            $quotedMsgIds = array_filter($quotedMsgIds, 'is_numeric');
            $quotedMsgIds = array_map('intval', $quotedMsgIds);
            if (!empty($quotedMsgIds)) {
                // Ensure unique IDs
                $quotedMsgIds = array_unique($quotedMsgIds);
                // Re-index array
                $quotedMsgIds = array_values($quotedMsgIds);
                $quotedJson = json_encode($quotedMsgIds);
            }
        }

        // Используем UTC для хранения!
        $stmt = $this->db->prepare("INSERT INTO chat_messages (user_id, username, message, created_at, quoted_msg_ids) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)");
        $stmt->bind_param("isss", $userId, $username, $message, $quotedJson);
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

        // Удаление с установкой времени deleted_at
        // Также обновляем edited_at, чтобы стрим заметил изменение!
        $updateStmt = $this->db->prepare("UPDATE chat_messages SET is_deleted = 1, deleted_at = UTC_TIMESTAMP(), edited_at = UTC_TIMESTAMP() WHERE id = ?");
        $updateStmt->bind_param("i", $messageId);
        return $updateStmt->execute();
    }

    public function restoreMessage($messageId, $userId, $isAdmin = false) {
        $stmt = $this->db->prepare("SELECT user_id, deleted_at FROM chat_messages WHERE id = ?");
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
        $res = $stmt->get_result();

        if (!$res || !($row = $res->fetch_assoc())) {
            return false;
        }

        if (!$isAdmin) {
            if ($row['user_id'] != $userId) return false; // Чужое сообщение

            // Проверка времени на восстановление (10 минут)
            if ($row['deleted_at']) {
                $delTime = strtotime($row['deleted_at']);
                // Сравниваем с UTC, так как deleted_at в UTC
                if ((time() - $delTime) > 600) {
                    return false; // Время вышло
                }
            } else {
                // Если deleted_at нет, но оно удалено - странно, но запретим
                return false;
            }
        }

        // Восстановление: сбрасываем флаг и таймер, обновляем edited_at чтобы чат увидел изменение
        // edited_at обновляется принудительно, чтобы стрим подхватил изменение статуса
        $updateStmt = $this->db->prepare("UPDATE chat_messages SET is_deleted = 0, deleted_at = NULL, edited_at = UTC_TIMESTAMP() WHERE id = ?");
        $updateStmt->bind_param("i", $messageId);
        return $updateStmt->execute();
    }

    private function processMessages($messages) {
        // Collect all quoted message IDs
        $allQuotedIds = [];
        foreach ($messages as $msg) {
            if (!empty($msg['quoted_msg_ids'])) {
                $ids = json_decode($msg['quoted_msg_ids'], true);
                if (is_array($ids)) {
                    $allQuotedIds = array_merge($allQuotedIds, $ids);
                }
            }
        }
        
        $quotedDetails = [];
        if (!empty($allQuotedIds)) {
            $allQuotedIds = array_unique($allQuotedIds);
            // Fetch details for these messages
            // Beware of too many IDs, but usually it's small.
            $idsStr = implode(',', array_map('intval', $allQuotedIds));
            
            if ($idsStr) {
                // Fetch minimal details for quoting
                $qQuery = "SELECT cm.id, cm.username, cm.message, cm.created_at, cm.is_deleted, u.chat_color, u.avatar_url 
                           FROM chat_messages cm
                           LEFT JOIN users u ON cm.user_id = u.id
                           WHERE cm.id IN ($idsStr)";
                $qRes = $this->db->query($qQuery);
                if ($qRes) {
                    while ($qRow = $qRes->fetch_assoc()) {
                        // Format date for quoted msg too
                         if ($qRow['created_at']) {
                            $qRow['created_at'] = date('Y-m-d\TH:i:s\Z', strtotime($qRow['created_at']));
                        }
                        // Handle deleted content
                        if ($qRow['is_deleted']) {
                             $qRow['message'] = '<em style="color:#999;">Сообщение удалено</em>';
                             $qRow['deleted'] = true;
                        }
                        $quotedDetails[$qRow['id']] = $qRow;
                    }
                }
            }
        }

        foreach ($messages as &$msg) {
            // Форматируем дату в ISO 8601 UTC (добавляем Z)
            if ($msg['created_at']) {
                $msg['created_at'] = date('Y-m-d\TH:i:s\Z', strtotime($msg['created_at']));
            }
            if ($msg['edited_at']) {
                $msg['edited_at'] = date('Y-m-d\TH:i:s\Z', strtotime($msg['edited_at']));
            } else {
                $msg['edited_at'] = null; // Явно null, если нет
            }
            if ($msg['deleted_at']) {
                $msg['deleted_at'] = date('Y-m-d\TH:i:s\Z', strtotime($msg['deleted_at']));
            } else {
                $msg['deleted_at'] = null;
            }

            if (!empty($msg['is_deleted'])) {
                $msg['message'] = '<em style="color:#999;">Сообщение удалено</em>';
                // Можно добавить флаг, чтобы фронтенд знал
                $msg['deleted'] = true;
            }

            // Attach Quoted Messages
            $msg['quotes'] = [];
            if (!empty($msg['quoted_msg_ids'])) {
                $ids = json_decode($msg['quoted_msg_ids'], true);
                if (is_array($ids)) {
                    foreach ($ids as $qid) {
                        if (isset($quotedDetails[$qid])) {
                            $msg['quotes'][] = $quotedDetails[$qid];
                        }
                    }
                }
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
