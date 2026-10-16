<?php
require dirname(__DIR__) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') exit(2);
it_require_db();
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
$app = Core\Application::getInstance();
$config = Infra\ConfigManager::getInstance();
$original = $config->getOption('stream_url', null);
try {
    $config->setOption('stream_url', $argv[1] ?? 'https://player.twitch.tv/?channel=coffian');
    ob_start();
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><!-- APP_HEAD_STRINGS --><link rel="stylesheet" href="/assets/css/main.css"></head><body><script>window.privacyChoices={decided:true,analytics:false,external_media:true};</script><main style="width:800px;max-width:100%">';
    $app->includeComponent('VideoPlayer');
    echo '</main>';
    $app->showFooterScripts();
    echo '</body></html>';
    $html = ob_get_clean();
} finally {
    if ($original !== null) $config->setOption('stream_url', $original);
    else { $db=Infra\Database::getInstance()->getConnection();$db->query("DELETE FROM site_options WHERE key_name='stream_url'"); }
}
ob_start();
echo $html;
$app->finalize();
