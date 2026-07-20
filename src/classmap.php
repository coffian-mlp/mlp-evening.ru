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
    'CentrifugoService' => 'CentrifugoService.php',
    'ChatManager'       => 'ChatManager.php',
    'ConfigManager'     => 'ConfigManager.php',
    'Database'          => 'Database.php',
    'EpisodeManager'    => 'EpisodeManager.php',
    'EventManager'      => 'EventManager.php',
    'Mailer'            => 'Mailer.php',
    'OnlineManager'     => 'OnlineManager.php',
    'PollManager'       => 'PollManager.php',
    'SimpleSMTP'        => 'SimpleSMTP.php',
    'StickerManager'    => 'StickerManager.php',
    'UploadManager'     => 'UploadManager.php',
    'UserManager'       => 'UserManager.php',

    // src/LLM/
    'BotDispatch'          => 'LLM/BotDispatch.php',
    'BotWorker'            => 'LLM/BotWorker.php',
    'CliUtilityProvider'   => 'LLM/CliUtilityProvider.php',
    'GigaChatProvider'     => 'LLM/GigaChatProvider.php',
    'JobQueue'             => 'LLM/JobQueue.php',
    'LLMManager'           => 'LLM/LLMManager.php',
    'LLMProviderInterface' => 'LLM/LLMProviderInterface.php',
    'OpenAIProvider'       => 'LLM/OpenAIProvider.php',
    'OpenRouterProvider'   => 'LLM/OpenRouterProvider.php',
    'ReactionParser'       => 'LLM/ReactionParser.php',
    'ReplyPolicy'          => 'LLM/ReplyPolicy.php',
    'ResponseSanitizer'    => 'LLM/ResponseSanitizer.php',
    'RouterAIProvider'     => 'LLM/RouterAIProvider.php',
    'VisionFormatter'      => 'LLM/VisionFormatter.php',
    'XrayManager'          => 'LLM/XrayManager.php',
    'YandexGPTProvider'    => 'LLM/YandexGPTProvider.php',

    // src/Social/
    'SocialAuthService' => 'Social/SocialAuthService.php',
    'SocialProvider'    => 'Social/SocialProvider.php',
    'TelegramProvider'  => 'Social/TelegramProvider.php',

    // src/Api/
    'EventController' => 'Api/EventController.php',
    'PinController'   => 'Api/PinController.php',
    'PollController'  => 'Api/PollController.php',
];
