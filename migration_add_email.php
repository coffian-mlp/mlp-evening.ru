<?php
require_once __DIR__ . '/src/Database.php';

$db = Database::getInstance()->getConnection();

echo "Starting migration: Adding email and reset token fields to users table...\n";

// 1. Add email field
try {
    $db->query("ALTER TABLE users ADD COLUMN email VARCHAR(255) DEFAULT NULL UNIQUE AFTER nickname");
    echo "[OK] Added email column.\n";
} catch (Exception $e) {
    echo "[SKIP] Email column might already exist or error: " . $db->error . "\n";
}

// 2. Add reset_token_hash field
try {
    $db->query("ALTER TABLE users ADD COLUMN reset_token_hash VARCHAR(64) DEFAULT NULL AFTER ban_reason");
    echo "[OK] Added reset_token_hash column.\n";
} catch (Exception $e) {
    echo "[SKIP] reset_token_hash column might already exist or error: " . $db->error . "\n";
}

// 3. Add reset_token_expires field
try {
    $db->query("ALTER TABLE users ADD COLUMN reset_token_expires DATETIME DEFAULT NULL AFTER reset_token_hash");
    echo "[OK] Added reset_token_expires column.\n";
} catch (Exception $e) {
    echo "[SKIP] reset_token_expires column might already exist or error: " . $db->error . "\n";
}

echo "Migration completed.\n";
