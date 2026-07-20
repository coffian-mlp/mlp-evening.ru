<?php
namespace Components\AdminBotCommands;

use Core\Component;
use Domain\Auth;
use Domain\BotCommandManager;

class AdminBotCommandsComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        // MLP-255: мутации (save/delete_bot_command) переехали в api.php →
        // Api\BotCommandController; компонент только рендерит.
        $commands = new BotCommandManager();
        $this->result['commands'] = $commands->getAll();

        $this->includeTemplate();
    }
}
