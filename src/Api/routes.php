<?php

/**
 * Карта тонкого роутера api.php (MLP-229/245/255): action → роль → хендлер.
 * Роли: public | user | moderator | admin (линейная иерархия, гейтит api.php).
 * Отдельный файл — чтобы карту читали тесты (tests/integration_api_routes.php)
 * без исполнения api.php. Подключается: $apiRoutes = require __DIR__ . '/src/Api/routes.php';
 */

return [
    'get_public_events' => ['role' => 'public', 'handler' => [\Api\EventController::class, 'getPublic']],
    'save_event'        => ['role' => 'admin',  'handler' => [\Api\EventController::class, 'save']],
    'delete_event'      => ['role' => 'admin',  'handler' => [\Api\EventController::class, 'delete']],
    // Опросы (MLP-238): create_poll тонко гейтит сам контроллер (конфиг polls_create_role).
    'get_poll'          => ['role' => 'public', 'handler' => [\Api\PollController::class, 'get']],
    'create_poll'       => ['role' => 'user',   'handler' => [\Api\PollController::class, 'create']],
    'vote_poll'         => ['role' => 'user',   'handler' => [\Api\PollController::class, 'vote']],
    'close_poll'        => ['role' => 'user',   'handler' => [\Api\PollController::class, 'close']],
    // Закреплённые сообщения (MLP-242): право модератора проверяет сам контроллер.
    'get_pinned'        => ['role' => 'public', 'handler' => [\Api\PinController::class, 'get']],
    'pin_message'       => ['role' => 'user',   'handler' => [\Api\PinController::class, 'pin']],
    'unpin_message'     => ['role' => 'user',   'handler' => [\Api\PinController::class, 'unpin']],
    // Капча и онлайн-присутствие (MLP-245): публичные, CSRF-гейт выше как раньше.
    // Чат (MLP-265, финал среза AR5-6)
    'get_chat_input'     => ['role' => 'public', 'handler' => [\Api\ChatController::class, 'getInput']],
    'get_messages'       => ['role' => 'public', 'handler' => [\Api\ChatController::class, 'getMessages']],
    'search_messages'    => ['role' => 'user',   'handler' => [\Api\ChatController::class, 'search']],
    'get_message_context'=> ['role' => 'user',   'handler' => [\Api\ChatController::class, 'getContext']],
    'toggle_reaction'    => ['role' => 'user',   'handler' => [\Api\ChatController::class, 'toggleReaction']],
    'send_message'       => ['role' => 'user',   'handler' => [\Api\ChatController::class, 'send']],
    'edit_message'       => ['role' => 'user',   'handler' => [\Api\ChatController::class, 'edit']],
    'delete_message'     => ['role' => 'user',   'handler' => [\Api\ChatController::class, 'delete']],
    'restore_message'    => ['role' => 'user',   'handler' => [\Api\ChatController::class, 'restore']],
    'upload_file'        => ['role' => 'user',   'handler' => [\Api\ChatController::class, 'uploadFile']],
    // Метрики Лиры (MLP-280)
    'get_lyra_metrics'    => ['role' => 'admin', 'handler' => [\Api\BotMetricsController::class, 'get']],
    // Беклог фидбека /todo (MLP-270)
    'get_feedback'        => ['role' => 'admin', 'handler' => [\Api\FeedbackController::class, 'list']],
    'set_feedback_status' => ['role' => 'admin', 'handler' => [\Api\FeedbackController::class, 'setStatus']],
    // Auth и профиль (MLP-264, срез AR5-6)
    'login'                => ['role' => 'public', 'handler' => [\Api\AuthController::class, 'login']],
    'register'             => ['role' => 'public', 'handler' => [\Api\AuthController::class, 'register']],
    'forgot_password'      => ['role' => 'public', 'handler' => [\Api\AuthController::class, 'forgotPassword']],
    'reset_password_submit'=> ['role' => 'public', 'handler' => [\Api\AuthController::class, 'resetPasswordSubmit']],
    'social_login'         => ['role' => 'public', 'handler' => [\Api\AuthController::class, 'socialLogin']],
    'logout'               => ['role' => 'user',   'handler' => [\Api\AuthController::class, 'logout']],
    'bind_social'          => ['role' => 'user',   'handler' => [\Api\AuthController::class, 'bindSocial']],
    'update_profile'       => ['role' => 'user',   'handler' => [\Api\ProfileController::class, 'update']],
    'save_user_option'     => ['role' => 'user',   'handler' => [\Api\ProfileController::class, 'saveOption']],
    'get_user_socials'     => ['role' => 'user',   'handler' => [\Api\ProfileController::class, 'getSocials']],
    'unlink_social'        => ['role' => 'user',   'handler' => [\Api\ProfileController::class, 'unlinkSocial']],
    'captcha_start'     => ['role' => 'public', 'handler' => [\Api\CaptchaController::class, 'start']],
    'captcha_check'     => ['role' => 'public', 'handler' => [\Api\CaptchaController::class, 'check']],
    'heartbeat'         => ['role' => 'public', 'handler' => [\Api\OnlineController::class, 'beat']],
    'leave'             => ['role' => 'public', 'handler' => [\Api\OnlineController::class, 'leave']],
    // Настройки и плейлист (MLP-255): админский пласт переезжает из switch.
    'update_settings'    => ['role' => 'admin', 'handler' => [\Api\SettingsController::class, 'update']],
    'regenerate_playlist'=> ['role' => 'admin', 'handler' => [\Api\PlaylistController::class, 'regenerate']],
    'vote'               => ['role' => 'admin', 'handler' => [\Api\PlaylistController::class, 'vote']],
    'mark_watched'       => ['role' => 'admin', 'handler' => [\Api\PlaylistController::class, 'markWatched']],
    'clear_votes'        => ['role' => 'admin', 'handler' => [\Api\PlaylistController::class, 'clearVotes']],
    'reset_times_watched'=> ['role' => 'admin', 'handler' => [\Api\PlaylistController::class, 'resetTimesWatched']],
    'clear_watching_log' => ['role' => 'admin', 'handler' => [\Api\PlaylistController::class, 'clearWatchingLog']],
    // Администрирование пользователей (MLP-255).
    'get_users'          => ['role' => 'admin', 'handler' => [\Api\UserAdminController::class, 'getUsers']],
    'get_user_options'   => ['role' => 'admin', 'handler' => [\Api\UserAdminController::class, 'getUserOptions']],
    'get_audit_logs'     => ['role' => 'admin', 'handler' => [\Api\UserAdminController::class, 'getAuditLogs']],
    'save_user'          => ['role' => 'admin', 'handler' => [\Api\UserAdminController::class, 'save']],
    'delete_user'        => ['role' => 'admin', 'handler' => [\Api\UserAdminController::class, 'delete']],
    // Соц-привязки из карточки пользователя (MLP-258; профильные get_user_socials/unlink_social — про СВОЕГО юзера)
    'get_user_socials_admin' => ['role' => 'admin', 'handler' => [\Api\UserAdminController::class, 'getUserSocials']],
    'unlink_social_admin'    => ['role' => 'admin', 'handler' => [\Api\UserAdminController::class, 'unlinkSocial']],
    // Модерация (MLP-255): иерархия ролей — внутри контроллера.
    'ban_user'           => ['role' => 'moderator', 'handler' => [\Api\ModerationController::class, 'ban']],
    'unban_user'         => ['role' => 'moderator', 'handler' => [\Api\ModerationController::class, 'unban']],
    'mute_user'          => ['role' => 'moderator', 'handler' => [\Api\ModerationController::class, 'mute']],
    'unmute_user'        => ['role' => 'moderator', 'handler' => [\Api\ModerationController::class, 'unmute']],
    'purge_messages'     => ['role' => 'moderator', 'handler' => [\Api\ModerationController::class, 'purge']],
    // Стикеры и паки (MLP-255): чтение публичное (пикер чата, в т.ч. гости).
    'get_packs'          => ['role' => 'public', 'handler' => [\Api\StickerController::class, 'getPacks']],
    'get_stickers'       => ['role' => 'public', 'handler' => [\Api\StickerController::class, 'getStickers']],
    'create_pack'        => ['role' => 'admin',  'handler' => [\Api\StickerController::class, 'createPack']],
    'update_pack'        => ['role' => 'admin',  'handler' => [\Api\StickerController::class, 'updatePack']],
    'delete_pack'        => ['role' => 'admin',  'handler' => [\Api\StickerController::class, 'deletePack']],
    'add_sticker'        => ['role' => 'admin',  'handler' => [\Api\StickerController::class, 'add']],
    'import_zip_stickers'=> ['role' => 'admin',  'handler' => [\Api\StickerController::class, 'importZip']],
    'delete_sticker'     => ['role' => 'admin',  'handler' => [\Api\StickerController::class, 'delete']],
    // Команды бота (MLP-255): переезд с POST-обработчика dashboard/index.php.
    'save_bot_command'   => ['role' => 'admin', 'handler' => [\Api\BotCommandController::class, 'save']],
    'delete_bot_command' => ['role' => 'admin', 'handler' => [\Api\BotCommandController::class, 'delete']],
    // Меню сайта (MLP-259).
    'get_menu_items'     => ['role' => 'admin', 'handler' => [\Api\MenuController::class, 'getItems']],
    'save_menu_item'     => ['role' => 'admin', 'handler' => [\Api\MenuController::class, 'save']],
    'delete_menu_item'   => ['role' => 'admin', 'handler' => [\Api\MenuController::class, 'delete']],
    'move_menu_item'     => ['role' => 'admin', 'handler' => [\Api\MenuController::class, 'move']],
    // Панель БД (MLP-255): переезд с db_action-блока dashboard/index.php.
    // db_export отдаёт CSV (не JSON) — заголовки переопределяет контроллер.
    'db_get_row'         => ['role' => 'admin', 'handler' => [\Api\DbAdminController::class, 'getRow']],
    'db_update_row'      => ['role' => 'admin', 'handler' => [\Api\DbAdminController::class, 'updateRow']],
    'db_export'          => ['role' => 'admin', 'handler' => [\Api\DbAdminController::class, 'export']],
];
