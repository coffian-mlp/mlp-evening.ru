<?php

namespace Social;

use Infra\ConfigManager;


class TelegramProvider implements SocialProvider {
    private $botToken;

    public function __construct() {
        // Токен берем из ConfigManager (БД)
        $configManager = ConfigManager::getInstance();
        // Второй параметр - дефолтное значение (пустое, если не задано)
        $this->botToken = $configManager->getOption('telegram_bot_token', '');
    }

    public function getName(): string {
        return 'telegram';
    }

    public function validateCallback(array $data): ?array {
        if (empty($this->botToken)) {
            error_log("TelegramProvider: Bot token is not set!");
            return null;
        }

        // Telegram Login Widget Logic
        // 1. Проверяем наличие hash
        if (!isset($data['hash'])) {
            return null;
        }

        $checkHash = $data['hash'];
        unset($data['hash']);

        // 2. Сортируем массив по ключам и собираем строку data_check_string
        $dataCheckArr = [];
        foreach ($data as $key => $value) {
            $dataCheckArr[] = $key . '=' . $value;
        }
        sort($dataCheckArr);
        $dataCheckString = implode("\n", $dataCheckArr);

        // 3. Вычисляем хэш: HMAC-SHA256(data_check_string, SHA256(bot_token))
        $secretKey = hash('sha256', $this->botToken, true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        // 4. Сравниваем (timing-safe, L2)
        if (!hash_equals($hash, (string)$checkHash)) {
            error_log("TelegramProvider: Hash mismatch!");
            return null;
        }

        // 5. Проверяем актуальность (auth_date) - чтобы не подсунули старый запрос (L2: guard isset)
        if (!isset($data['auth_date']) || (time() - (int)$data['auth_date']) > 86400) {
            error_log("TelegramProvider: Data is outdated or auth_date missing!");
            return null;
        }

        // Возвращаем нормализованные данные
        return [
            'id' => $data['id'],
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'username' => $data['username'] ?? '',
            'photo_url' => $data['photo_url'] ?? null
        ];
    }
}
