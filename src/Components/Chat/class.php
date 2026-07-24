<?php
namespace Components\Chat;

use Core\Component;
use Domain\Auth;
use Domain\UserManager;
use Domain\StickerManager;
use Infra\CentrifugoService;
use Infra\ConfigManager;

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
        
        // 3. Конфигурация чата (Centrifugo / SSE) — из .env (MLP-252)
        $chatDriver = \Infra\Env::get('CHAT_DRIVER', 'sse');
        
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

        // Ядро чата (MLP-267, AR6-3): единые CSS/JS обоих шаблонов — ДО шаблонных
        // ассетов (includeTemplate ниже), чтобы popup/style.css мог переопределять.
        global $app;
        $app->addCss('/src/Components/Chat/assets/chat-core.css');
        $app->addJs('/src/Components/Chat/assets/chat-core.js');

        // Виджет опросов монтируется внутри DOM чата — подключаем его ассеты вместе с чатом (MLP-239).
        $app->addCss('/src/Components/Poll/templates/default/style.css');
        $app->addJs('/src/Components/Poll/templates/default/script.js');

        // Право текущего пользователя создавать опросы (для кнопки в тулбаре).
        $config = ConfigManager::getInstance();
        $pollsRole = $config->getOption('polls_create_role', 'moderator');
        $canCreatePoll = false;
        if (Auth::check()) {
            if ($pollsRole === 'all')       $canCreatePoll = true;
            elseif ($pollsRole === 'admin') $canCreatePoll = Auth::isAdmin();
            else                            $canCreatePoll = Auth::isModerator();
        }
        $this->result['can_create_poll'] = $canCreatePoll;

        // MLP-278: превью команд при вводе «/» — список активных команд бота
        // (префикс+описание). /опрос показываем только тем, кому позволено.
        $botCommands = [];
        if (Auth::check()) {
            $bcm = new \Domain\BotCommandManager();
            if ($bcm->isAvailable()) {
                foreach ($bcm->getActive() as $cmd) {
                    if (($cmd['handler_type'] ?? '') === 'poll' && !$canCreatePoll) {
                        continue;
                    }
                    $prefix = trim((string)($cmd['command_prefix'] ?? ''));
                    if ($prefix === '') continue;
                    $botCommands[] = [
                        'prefix' => '/' . ltrim($prefix, '/'),
                        'description' => (string)($cmd['description'] ?? ''),
                    ];
                }
            }
        }
        $this->result['bot_commands'] = $botCommands;

        // Подключаем шаблон
        $this->includeTemplate();
    }
}
