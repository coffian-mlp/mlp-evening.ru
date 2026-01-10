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
     * Returns detailed online stats: list of users and count of guests.
     */
    public function getOnlineStats($window = 5) {
        // 1. Get Guests Count (unauthenticated sessions)
        // Note: We count distinct session_ids just in case, though session_id is unique key
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

        // 2. Get Users List (authenticated sessions)
        // We group by user_id to handle multiple sessions for same user (e.g. phone + desktop)
        $stmtUsers = $this->db->prepare("
            SELECT u.id, u.nickname, u.chat_color 
            FROM online_sessions os
            JOIN users u ON os.user_id = u.id
            WHERE os.last_seen > NOW() - INTERVAL ? MINUTE
            GROUP BY u.id
            ORDER BY u.nickname ASC
        ");
        $stmtUsers->bind_param("i", $window);
        $stmtUsers->execute();
        $resUsers = $stmtUsers->get_result();
        
        $users = [];
        while ($row = $resUsers->fetch_assoc()) {
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
