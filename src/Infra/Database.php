<?php

namespace Infra;

use mysqli;

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        // MLP-252: конфигурация из .env (fallback на legacy config.php — см. Env).
        $this->connection = new mysqli(
            Env::get('DB_HOST'),
            Env::get('DB_USER'),
            Env::get('DB_PASS'),
            Env::get('DB_NAME')
        );

        if ($this->connection->connect_error) {
            die('Ошибка подключения к базе данных: ' . $this->connection->connect_error);
        }

        $this->connection->set_charset(Env::get('DB_CHARSET', 'utf8mb4'));
    }

    private function __clone() {}

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}