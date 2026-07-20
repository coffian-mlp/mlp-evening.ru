<?php

namespace Api;

use Domain\BotCommandManager;

/**
 * Обработчики API-действий для команд бота (MLP-255): CRUD переехал сюда
 * с POST-обработчика в компоненте AdminBotCommands (dashboard/index.php) —
 * компонент теперь только рендерит. Ответы — глобальной sendResponse()
 * (api.php); роль (admin) и CSRF проверяет api.php ДО вызова.
 */
class BotCommandController {

    /** Создать (id пуст) или обновить (id > 0) команду бота (admin). */
    public static function save(): void {
        $id = (int)($_POST['id'] ?? 0);
        $command_prefix = trim($_POST['command_prefix'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $handler_type = $_POST['handler_type'] ?? 'text';
        $system_prompt = trim($_POST['system_prompt'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($command_prefix === '' || $description === '') {
            sendResponse(false, "Команда и описание обязательны", 'error');
        }

        $commands = new BotCommandManager();
        if ($id > 0) {
            $commands->update($id, $command_prefix, $description, $handler_type, $system_prompt, $is_active);
            sendResponse(true, "Команда обновлена!", 'success', ['reload' => true]);
        } else {
            $commands->create($command_prefix, $description, $handler_type, $system_prompt, $is_active);
            sendResponse(true, "Команда создана! 🤖", 'success', ['reload' => true]);
        }
    }

    /** Удалить команду бота (admin). */
    public static function delete(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) sendResponse(false, "ID не указан", 'error');

        (new BotCommandManager())->delete($id);
        sendResponse(true, "Команда удалена 🗑️", 'success', ['reload' => true]);
    }
}
