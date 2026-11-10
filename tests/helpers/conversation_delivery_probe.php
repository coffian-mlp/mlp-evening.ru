<?php
require dirname(__DIR__,2).'/autoload.php';
if(!is_file('/.dockerenv')||Infra\Env::get('DB_NAME')!=='mlp_phase3')throw new RuntimeException('Synthetic only');
$state=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$mode=$argv[2];$db=Infra\Database::getInstance()->getConnection();
if($mode==='token'){echo json_encode(['result'=>(new Domain\ConversationMemoryStore())->publishArrival($state['uid'],$state['arrival']['token'],$state['bot'],'Synthetic',$state['text'])]),"\n";exit;}
if($mode==='peer'){
 $lock='chat_add_'.(int)$state['bot'];$got=(int)$db->query("SELECT GET_LOCK('$lock',0)")->fetch_row()[0];$result=false;
 if($got){try{$result=(new Domain\ChatManager())->addMessage($state['bot'],'Synthetic',$state['text']);}finally{$db->query("SELECT RELEASE_LOCK('$lock')");}}
 echo json_encode(['lock_acquired'=>$got,'result'=>$result]),"\n";exit;
}
function conversationDeliveryPeer(string $kind='peer'):array{global $argv;$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,$argv[1],$kind],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as$x)fclose($x);if(proc_close($p)||$err!=='')throw new RuntimeException('Delivery peer failed');return json_decode($out,true,512,JSON_THROW_ON_ERROR);}
function conversationDeliveryBarrier():void{global $mode;$GLOBALS['delivery_peer']=conversationDeliveryPeer();if($mode==='return')$GLOBALS['token_peer']=conversationDeliveryPeer('token');}
$source=file_get_contents(dirname(__DIR__,2).'/src/Domain/ConversationMemoryStore.php');
if($mode==='ordinary')$source=str_replace('$id=$chat->addMessage($bot,$name,$text,$quotes,true);','$id=$chat->addMessage($bot,$name,$text,$quotes,true);\\conversationDeliveryBarrier();',$source);
else{$needle='$s=$this->db->prepare("UPDATE bot_memory_arrivals SET state=\'done\'';$source=str_replace($needle,'\\conversationDeliveryBarrier();'.$needle,$source);}
$file=dirname(__DIR__,2).'/delivery-probe-copy.php';file_put_contents($file,$source);spl_autoload_register(static function($n)use($file){if($n==='Domain\\ConversationMemoryStore')require$file;},true,true);
try{
 $l=new LLM\LLMManager();if($mode==='return')(new ReflectionProperty($l,'conversationArrival'))->setValue($l,$state['arrival']);
 $work=fn()=>$l->publishConversationReply($state['text']);$id=$mode==='ordinary'&&method_exists($l,'withConversationJobs')?$l->withConversationJobs([$state['job']],$work):$work();
 $peer=$GLOBALS['delivery_peer']??conversationDeliveryPeer();
 echo json_encode(['result'=>$id,'peer'=>$peer,'token_peer'=>$GLOBALS['token_peer']??[],'messages'=>(int)$db->query('SELECT COUNT(*) FROM chat_messages WHERE user_id='.(int)$state['bot'])->fetch_row()[0]]),"\n";
}finally{unlink($file);}
