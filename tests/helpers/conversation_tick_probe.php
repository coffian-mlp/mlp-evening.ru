<?php
$root=isset($argv[1])&&$argv[1]==='baseline'?dirname(__DIR__,2).'/baseline':dirname(__DIR__,2);
require_once $root.'/autoload.php';
if(!is_file('/.dockerenv')||Infra\Env::get('DB_NAME')!=='mlp_phase3')throw new RuntimeException('Synthetic only');
$d=Infra\Database::getInstance()->getConnection();$w=new LLM\BotWorker();$w->tick();$w->tick();$counts=[];
for($i=0;$i<12;$i++){$before=(int)$d->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch_row()[1];$w->tick();$counts[]=(int)$d->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch_row()[1]-$before-1;}
echo json_encode(['mode'=>$argv[1]??'current','min'=>min($counts),'max'=>max($counts),'samples'=>count($counts)]),"\n";
