<?php

require_once __DIR__ . '/Database.php';

class ConfigManager {
    private $db;
    private static $instance = null;

    private function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getOption($key, $default = null) {
        $stmt = $this->db->prepare("SELECT value FROM site_options WHERE key_name = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res && $row = $res->fetch_assoc()) {
            return $row['value'];
        }
        return $default;
    }

    public function getOptionDetails($key) {
        $stmt = $this->db->prepare("SELECT value, updated_at FROM site_options WHERE key_name = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }
        return null;
    }

    public function setOption($key, $value) {
        $stmt = $this->db->prepare("INSERT INTO site_options (key_name, value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()");
        $stmt->bind_param("sss", $key, $value, $value);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}

