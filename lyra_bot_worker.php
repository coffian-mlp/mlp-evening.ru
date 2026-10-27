<?php
/** Lightweight private bot updates; publication and generation remain in cron_announcements.php. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/autoload.php';
$loop=in_array('--loop',$argv,true);
do {
    $started=microtime(true);
    try {
        \Infra\ConfigManager::getInstance()->flushCache();
        $settings=\Domain\AnnouncementSettings::values();
        if($settings['announcements_token']!==''){
            $store=new \Domain\AnnouncementStore();$client=\Domain\AnnouncementSettings::client($settings);$editor=null;
            if($settings['announcements_enabled']==='1')$editor=new \LLM\AnnouncementWorker($store,$client,new \LLM\AnnouncementContent(),(int)$settings['announcements_owner_id'],$settings['announcements_channel'],(int)$settings['announcements_event_id'],(int)$settings['announcements_next_number']);
            (new \LLM\LyraBotPoller($store,$client,$editor,null,20))->tick();
        }
        \Infra\ConfigManager::getInstance()->setOption('lyra_bot_heartbeat',gmdate('Y-m-d H:i:s'));
    }catch(\LLM\BotPollBusy $e){ /* The durable update stays pending until the publisher releases its lock. */ }
    catch(\Throwable $e){(new \Infra\AnnouncementLog())->record('poll_failed',[],$e);error_log('Lyra private bot tick failed: '.get_class($e));}
    if($loop)usleep((int)(max(0.1,3-(microtime(true)-$started))*1000000));
}while($loop);
