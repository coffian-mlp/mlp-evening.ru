<?php
require_once dirname(__DIR__,2).'/autoload.php';
if(!is_file('/.dockerenv')||Infra\Env::get('DB_NAME')!=='mlp_phase3')throw new RuntimeException('Synthetic only');
$state=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$source=file_get_contents(dirname(__DIR__,2).'/src/Domain/ConversationMemoryStore.php');
$needle="\$s=\$this->db->prepare('UPDATE bot_conversation_summaries SET summary_text=?,";
if(substr_count($source,$needle)!==1)throw new RuntimeException('Publication barrier seam missing');
$source=str_replace($needle,"\\conversationPublicationBarrier();".$needle,$source);
$file=dirname(__DIR__,2).'/publication-probe-copy.php';file_put_contents($file,$source);
spl_autoload_register(static function($name)use($file){if($name==='Domain\\ConversationMemoryStore')require$file;},true,true);
function conversationPublicationBarrier(): void {echo "ready\n";flush();if(trim(fgets(STDIN))!=='continue')throw new RuntimeException('Barrier interrupted');}
try{$s=new Domain\ConversationMemoryStore();$r=$s->record($state['key']);$f=$s->frame($state['bot']);$r['revision']=$state['revision'];$accepted=$s->publish($f,$r,'Недопустимая генерация после восстановления',$state['cursor'],$state['hash'],$state['bot'],time(),microtime(true)+20);echo 'done:'.(int)$accepted."\n";}
finally{unlink($file);}
