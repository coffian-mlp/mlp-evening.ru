<?php
/** Separate synthetic processes exercise real trigger, worker and publication paths. */
require dirname(__DIR__,2).'/autoload.php';
if(!is_file('/.dockerenv')||Infra\Env::get('DB_NAME')!=='mlp_phase3')throw new RuntimeException('Synthetic contour only');
$s=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$mode=$argv[2];$db=Infra\Database::getInstance()->getConnection();$prepared=[];
function slotWithdraw():void{global $s;Domain\Privacy::setAccountConsent($s['uid'],false,false);Domain\Privacy::unlockAccount($s['uid']);}
class SlotFake implements LLM\LLMProviderInterface{
 public int $calls=0;
 public function __construct(private int $number,private ?Closure $during=null){}
 public function withTimeout(int $n):static{return$this;}
 public function askChat(array $c,string $p):?string{global $prepared;$this->calls++;$j=json_encode($c,JSON_UNESCAPED_UNICODE);$r=['number'=>$this->number,'summary'=>str_contains($j,LLM\ConversationMemoryPolicy::SUMMARY_MARKER),'return'=>str_contains($j,LLM\ConversationMemoryPolicy::RETURN_MARKER)];$prepared[]=$r;if($this->during)($this->during)($r);return ($r['summary']||$r['return']?'Защищённый':'Нейтральный').' синтетический ответ '.$this->number.'.';}
}
function slotLLM(SlotFake $p):LLM\LLMManager{$l=new LLM\LLMManager();foreach(['providers','fastProviders']as$x)(new ReflectionProperty($l,$x))->setValue($l,[$p]);return$l;}
if($mode==='inline-pair'){
 $second=slotLLM(new SlotFake(2,fn()=>slotWithdraw()));$first=slotLLM(new SlotFake(1,function()use($second,$s){$second->processTrigger('greeting',['user_id'=>$s['actors'][1],'username'=>'SyntheticC']);}));$first->processTrigger('greeting',['user_id'=>$s['actors'][0],'username'=>'SyntheticB']);
}elseif($mode==='inline'){
 slotLLM(new SlotFake(3,fn()=>slotWithdraw()))->processTrigger('greeting',['user_id'=>$s['actors'][1],'username'=>'SyntheticC']);
}elseif($mode==='worker'||$mode==='second-worker'){
 $p=new SlotFake($mode==='worker'?4:5,$mode==='worker'?function($r){echo json_encode(['ready'=>true]+$r),"\n";flush();if(trim(fgets(STDIN))!=='continue')throw new RuntimeException('Barrier interrupted');}:null);$w=new LLM\BotWorker();(new ReflectionProperty($w,'llm'))->setValue($w,slotLLM($p));$w->tick();
}elseif($mode==='connection-loss'){
 $before=spl_object_id($db);$id=$db->thread_id;$db->query("SELECT GET_LOCK('bot_worker',0)");$p=new SlotFake(6,function()use($id){$other=new mysqli('db',Infra\Env::get('DB_USER'),Infra\Env::get('DB_PASS'),Infra\Env::get('DB_NAME'));$other->query('KILL CONNECTION '.(int)$id);$other->close();});$failed=false;
 try{slotLLM($p)->processTrigger('greeting',['user_id'=>$s['actors'][0],'username'=>'SyntheticB']);}catch(Throwable $e){$failed=true;}
 $same=spl_object_id(Infra\Database::getInstance()->getConnection())===$before;$queryFailed=false;try{$db->query('SELECT 1');}catch(Throwable $e){$queryFailed=true;}echo json_encode(['prepared'=>$prepared,'publication_failed'=>$failed,'same_connection_object'=>$same,'query_still_fails'=>$queryFailed]),"\n";exit;
}else throw new RuntimeException('Unknown slot mode');
echo json_encode(['prepared'=>$prepared]),"\n";
