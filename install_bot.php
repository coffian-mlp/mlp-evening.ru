<?php
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ConfigManager.php';
require_once __DIR__ . '/src/UserManager.php';

$db = Database::getInstance()->getConnection();
$userManager = new UserManager();
$config = ConfigManager::getInstance();

// 1. Check if Twilight already exists
$stmt = $db->prepare("SELECT id FROM users WHERE login = 'Twilight'");
$stmt->execute();
$res = $stmt->get_result();

$botId = null;
if ($row = $res->fetch_assoc()) {
    $botId = $row['id'];
    echo "Twilight already exists with ID: $botId\n";
} else {
    // Generate a random impossible password hash since she won't login via web
    $randomPass = bin2hex(random_bytes(16));
    $botId = $userManager->createUser('Twilight', $randomPass, 'user', 'Twilight Sparkle');
    echo "Created Twilight with ID: $botId\n";
}

if ($botId) {
    // 2. Set Avatar and Color
    $userManager->updateUser($botId, [
        'chat_color' => '#9b59b6', // Twilight Purple
        'avatar_url' => 'https://i.imgur.com/K12X8rO.png' // Placeholder Twilight avatar
    ]);
    
    // 3. Save to Config
    $config->setOption('ai_bot_user_id', $botId);
    $config->setOption('ai_enabled', 1);
    $config->setOption('ai_system_prompt', 'Ты — Твайлайт Спаркл, Принцесса Дружбы из My Little Pony. Ты просто участница чата брони-сайта, а не ассистент. Общайся непринужденно, используй поняшный сленг. НИКОГДА не предлагай помощь и не спрашивай "чем могу помочь", просто поддерживай беседу.');
    
    echo "Config updated successfully!\n";
} else {
    echo "Failed to create bot user.\n";
}
