<?php
namespace Components\Chat;

use Core\Component;
use Auth;
use UserManager;
use StickerManager;
use CentrifugoService;
use ConfigManager;

class ChatComponent extends Component {
    public function executeComponent() {
        // 1. Проверка авторизации и получение данных пользователя
        $this->result['user'] = null;
        $this->result['user_options'] = [];
        
        if (Auth::check()) {
            $userManager = new UserManager();
            $this->result['user'] = $userManager->getUserById($_SESSION['user_id']);
            $this->result['user_options'] = $userManager->getUserOptions($_SESSION['user_id']);
        }
        
        // 2. Получение стикеров
        $stickerManager = new StickerManager();
        $packs = $stickerManager->getAllPacks();
        $allStickers = $stickerManager->getAllStickers(true);
        
        $stickersByPack = [];
        foreach ($allStickers as $s) {
            $stickersByPack[$s['pack_id']][] = $s;
        }
        
        $this->result['sticker_map'] = $stickerManager->getStickerMap();
        $this->result['frontend_sticker_data'] = [
            'packs' => $packs,
            'stickers' => $stickersByPack
        ];
        
        // 3. Конфигурация чата (Centrifugo / SSE)
        $appConfig = require $_SERVER['DOCUMENT_ROOT'] . '/config.php';
        $chatConfig = $appConfig['chat'] ?? [];
        $chatDriver = $chatConfig['driver'] ?? 'sse';
        
        $this->result['chat_driver'] = $chatDriver;
        $this->result['centrifugo_token'] = '';
        $this->result['centrifugo_url'] = '/connection/websocket';
        
        if ($chatDriver === 'centrifugo') {
            $centrifugoService = new CentrifugoService();
            $sub = Auth::check() ? (string)$_SESSION['user_id'] : "guest_" . substr(session_id(), 0, 10);
            $this->result['centrifugo_token'] = $centrifugoService->generateToken($sub, time() + 86400);
        }
        
        // 4. Дополнительные настройки
        $config = ConfigManager::getInstance();
        $this->result['telegram_auth_enabled'] = (bool)$config->getOption('telegram_auth_enabled', 0);
        $this->result['telegram_bot_username'] = $config->getOption('telegram_bot_username', '');
        
        // Передаем параметры режима (popup/local)
        $this->result['mode'] = $this->params['mode'] ?? 'local';
        
        // Подключаем шаблон
        $this->includeTemplate();
    }
}
