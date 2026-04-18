<?php
namespace Components\AdminBotCommands;

use Core\Component;
use Auth;
use Database;

class AdminBotCommandsComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $db = Database::getInstance()->getConnection();

        // Handle forms
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'create_command' || $action === 'edit_command') {
                $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                $command_prefix = trim($_POST['command_prefix']);
                $description = trim($_POST['description']);
                $handler_type = $_POST['handler_type'];
                $system_prompt = trim($_POST['system_prompt']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if ($action === 'create_command') {
                    $stmt = $db->prepare("INSERT INTO bot_commands (command_prefix, description, handler_type, system_prompt, is_active) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssi", $command_prefix, $description, $handler_type, $system_prompt, $is_active);
                    $stmt->execute();
                } else {
                    $stmt = $db->prepare("UPDATE bot_commands SET command_prefix=?, description=?, handler_type=?, system_prompt=?, is_active=? WHERE id=?");
                    $stmt->bind_param("ssssii", $command_prefix, $description, $handler_type, $system_prompt, $is_active, $id);
                    $stmt->execute();
                }
                
                header("Location: /dashboard/index.php#tab-bot-commands");
                exit;
            }

            if ($action === 'delete_command') {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("DELETE FROM bot_commands WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                header("Location: /dashboard/index.php#tab-bot-commands");
                exit;
            }
        }

        // Fetch existing commands
        $this->result['commands'] = [];
        $res = $db->query("SELECT * FROM bot_commands ORDER BY command_prefix ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $this->result['commands'][] = $row;
            }
        }

        $this->includeTemplate();
    }
}
