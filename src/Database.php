<?php

require_once __DIR__ . '/../config.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $config = require __DIR__ . '/../config.php';
        $dbConfig = $config['db'];

        $this->connection = new mysqli(
            $dbConfig['host'],
            $dbConfig['user'],
            $dbConfig['pass'],
            $dbConfig['name']
        );

        if ($this->connection->connect_error) {
            die('Ошибка подключения к базе данных: ' . $this->connection->connect_error);
        }

        $this->connection->set_charset($dbConfig['charset']);
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