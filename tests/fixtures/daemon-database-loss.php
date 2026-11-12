<?php
/** Process-local failure injection; loaded only by test_daemon_database_loss.php. */
namespace {
    function daemonFixtureTick(): void {
        static $tick=0;
        if(++$tick>1){echo "NEXT_TICK\n";exit(0);}
        $class=getenv('MLP_DAEMON_ERROR_CLASS');
        throw new $class('synthetic query and private data must not be logged',(int)getenv('MLP_DAEMON_ERROR_CODE'));
    }
}
namespace Infra {
    class ConfigManager {
        public static function getInstance(): self {return new self();}
        public function flushCache(): void {\daemonFixtureTick();}
    }
    class AnnouncementLog {
        public function record(...$args): void {echo "ORDINARY_ERROR_RECORDED\n";}
    }
}
namespace Domain {
    class StreamMonitor {public function tick(): void {\daemonFixtureTick();}}
}
