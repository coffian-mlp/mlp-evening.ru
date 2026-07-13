<?php

require_once __DIR__ . '/Database.php';

class ConfigManager {
    private $db;
    private static $instance = null;

    // A2 (MLP-225): request-scoped кеш всех опций — одна выборка вместо N+1.
    private $cache = null;

    private function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Ленивая загрузка всех опций одним запросом. */
    private function loadAll() {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        $res = $this->db->query("SELECT key_name, value FROM site_options");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $this->cache[$row['key_name']] = $row['value'];
            }
        }
    }

    public function getOption($key, $default = null) {
        $this->loadAll();
        return array_key_exists($key, $this->cache) ? $this->cache[$key] : $default;
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
        // A2: держим кеш в актуальном состоянии.
        if ($result && $this->cache !== null) {
            $this->cache[$key] = $value;
        }
        return $result;
    }
}

