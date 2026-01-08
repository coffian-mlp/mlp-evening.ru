<?php
require_once __DIR__ . '/src/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Checking 'last_seen' column...\n";
    
    // Check if column exists
    $check = $db->query("SHOW COLUMNS FROM users LIKE 'last_seen'");
    if ($check && $check->num_rows > 0) {
        echo "Column 'last_seen' already exists. Skipping.\n";
    } else {
        echo "Adding 'last_seen' column...\n";
        $sql = "ALTER TABLE users ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL";
        if ($db->query($sql)) {
            echo "Success!\n";
            // Add index
            $db->query("CREATE INDEX idx_last_seen ON users(last_seen)");
            echo "Index created.\n";
        } else {
            echo "Error adding column: " . $db->error . "\n";
            exit(1);
        }
    }
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    exit(1);
}

