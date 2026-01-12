<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ConfigManager.php';

class OnlineManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Updates the heartbeat for the current session.
     */
    public function beat($sessionId, $userId = null, $ip = null, $ua = null) {
        $stmt = $this->db->prepare("
            INSERT INTO online_sessions (session_id, user_id, ip_address, user_agent, last_seen)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                last_seen = VALUES(last_seen),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent)
        ");
        
        $stmt->bind_param("siss", $sessionId, $userId, $ip, $ua);
        return $stmt->execute();
    }

    /**
     * Removes a session immediately (e.g. on window close).
     */
    public function removeSession($sessionId) {
        $stmt = $this->db->prepare("DELETE FROM online_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $sessionId);
        return $stmt->execute();
    }

    /**
     * Returns detailed online stats: list of users and count of guests.
     */
    public function getOnlineStats($window = 3) {
        // Мы всегда используем базу данных как источник истины.
        // Механизм heartbeat (AJAX) работает для всех клиентов, независимо от драйвера чата.
        // Это надежнее (стиль Земнопони 🌿), чем полагаться только на API Centrifugo,
        // который может быть недоступен для PHP или не учитывать гостей без WS.

        // 1. Guests Count (unauthenticated sessions)
        $stmtGuest = $this->db->prepare("
            SELECT COUNT(*) 
            FROM online_sessions 
            WHERE user_id IS NULL 
            AND last_seen > NOW() - INTERVAL ? MINUTE
        ");
        $stmtGuest->bind_param("i", $window);
        $stmtGuest->execute();
        $resGuest = $stmtGuest->get_result();
        $guestsCount = (int)$resGuest->fetch_row()[0];

        // 2. Users List (authenticated sessions)
        // We group by user_id to handle multiple sessions for same user
        // We fetch avatar from user_options (key: avatar_url)
        $stmtUsers = $this->db->prepare("
            SELECT u.id, u.nickname, uo_avatar.option_value as avatar, uo.option_value as chat_color 
            FROM online_sessions os
            JOIN users u ON os.user_id = u.id
            LEFT JOIN user_options uo ON u.id = uo.user_id AND uo.option_key = 'chat_color'
            LEFT JOIN user_options uo_avatar ON u.id = uo_avatar.user_id AND uo_avatar.option_key = 'avatar_url'
            WHERE os.last_seen > NOW() - INTERVAL ? MINUTE
            GROUP BY u.id
            ORDER BY u.nickname ASC
        ");
        $stmtUsers->bind_param("i", $window);
        $stmtUsers->execute();
        $resUsers = $stmtUsers->get_result();
        
        $users = [];
        while ($row = $resUsers->fetch_assoc()) {
            if (empty($row['chat_color'])) $row['chat_color'] = '#6d2f8e'; // Default color
            if (empty($row['avatar'])) $row['avatar'] = '/assets/img/default-avatar.png'; 
            $users[] = $row;
        }

        return [
            'guests_count' => $guestsCount,
            'users' => $users,
            'total' => $guestsCount + count($users)
        ];
    }
    
    /**
     * Removes old sessions to keep the table clean.
     */
    public function cleanup($minutes = 60) {
        $stmt = $this->db->prepare("DELETE FROM online_sessions WHERE last_seen < NOW() - INTERVAL ? MINUTE");
        $stmt->bind_param("i", $minutes);
        $stmt->execute();
    }
}
