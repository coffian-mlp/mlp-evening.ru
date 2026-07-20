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

        $commands = new BotCommandManager();

        // Handle forms
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            // MLP-243: CSRF на мутации команд бота (эндпоинт вне api.php).
            if (!Auth::checkCsrfToken($_POST['csrf_token'] ?? '')) {
                echo "Ошибка безопасности. Обнови страничку!";
                return;
            }
            $action = $_POST['action'];

            if ($action === 'create_command' || $action === 'edit_command') {
                $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                $command_prefix = trim($_POST['command_prefix']);
                $description = trim($_POST['description']);
                $handler_type = $_POST['handler_type'];
                $system_prompt = trim($_POST['system_prompt']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if ($action === 'create_command') {
                    $commands->create($command_prefix, $description, $handler_type, $system_prompt, $is_active);
                } else {
                    $commands->update($id, $command_prefix, $description, $handler_type, $system_prompt, $is_active);
                }

                header("Location: /dashboard/index.php#tab-bot-commands");
                exit;
            }

            if ($action === 'delete_command') {
                $commands->delete((int)$_POST['id']);
                header("Location: /dashboard/index.php#tab-bot-commands");
                exit;
            }
        }

        // Fetch existing commands
        $this->result['commands'] = $commands->getAll();

        $this->includeTemplate();
    }
}
