<?php
/**
 * Карта глобальных (без namespace) классов для autoload.php (MLP-248).
 * Временная: в фазе 3 (перекладка src/, ADR-7) классы получают namespaces
 * и уходят в PSR-4-ветку; карта должна опустеть и быть удалена.
 *
 * Новый глобальный класс обязан быть добавлен сюда — иначе упадёт
 * tests/test_autoload.php (скан src/ на не-namespaced классы).
 * Пути — относительно src/.
 */

return [
    // Корень src/ (менеджеры и сервисы)
    'Auth'              => 'Auth.php',
    'BotCommandManager' => 'BotCommandManager.php',
    'CaptchaManager'    => 'CaptchaManager.php',
    'ChatManager'       => 'ChatManager.php',
    'EpisodeManager'    => 'EpisodeManager.php',
    'EventManager'      => 'EventManager.php',
    'OnlineManager'     => 'OnlineManager.php',
    'PollManager'       => 'PollManager.php',
    'StickerManager'    => 'StickerManager.php',
    'UserManager'       => 'UserManager.php',
];
