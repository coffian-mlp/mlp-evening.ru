<?php
ob_start();
require_once __DIR__.'/integration_helpers.php';
$db=it_require_db();
require_once dirname(__DIR__).'/migrate.php';
use Domain\MemoryEpisodeManager;
use LLM\MemoryEpisodeBuilder;
use LLM\MemoryIndexer;
use LLM\MemoryAssociations;
use LLM\EmbeddingProviderInterface;

// Isolate the indexer from other integration fixtures and run all APIs through fakes.
$store=new MemoryEpisodeManager();$conn=$store->connection();
migrate_database($conn,dirname(__DIR__).'/migrations');
$marker='it_semantic_'.bin2hex(random_bytes(5));
$user=(new Domain\UserManager())->createUser($marker,'Aa!'.bin2hex(random_bytes(10)),'user','Semantic');
$second=(new Domain\UserManager())->createUser($marker.'_other','Aa!'.bin2hex(random_bytes(10)),'user','Other');
$ids=[];$memoryId=0;
function semanticRow(mysqli $db,int $user,string $text):int{
 $stmt=$db->prepare("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,'Semantic',?,UTC_TIMESTAMP())");$stmt->bind_param('is',$user,$text);$stmt->execute();return(int)$db->insert_id;
}
$fake=new class($conn) implements EmbeddingProviderInterface{
 public array $texts=[];public $after=null;
 public function __construct(private mysqli $db){}
 public function profile():string{return 'fake512-v1';}
 public function embed(array $texts,float $deadline):array{
  check(Infra\Transaction::depth($this->db)===0,'embedding API is outside transaction');
  $this->texts=array_merge($this->texts,$texts);
  if($this->after!==null){$callback=$this->after;$this->after=null;$callback();}
  return array_map(fn()=>array_fill(0,512,1/sqrt(512)),$texts);
 }
};
$associationCalls=0;
$associations=new MemoryAssociations(function($text,$prompt,$deadline)use(&$associationCalls,$conn){
 check(Infra\Transaction::depth($conn)===0,'association API is outside transaction');$associationCalls++;return 'Ace Attorney, Phoenix Wright, Феникс Райт, адвокат';
},'fake-cheap');
$builder=new MemoryEpisodeBuilder(12);
$indexer=new MemoryIndexer($store,$builder,$fake,$associations);
try{
 $output=[];$exitCode=0;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/cron_memory_embeddings.php'),$output,$exitCode);
 check($exitCode===0&&implode("\n",$output)==='Memory indexing disabled.','CLI defaults prevent source upload');
 $peer=it_db();
 $peer->query("SELECT GET_LOCK('memory_embeddings_indexer',0)");
 check(($indexer->run()['busy']??0)===1,'another indexer owns the independent lock');
 $peer->query("SELECT RELEASE_LOCK('memory_embeddings_indexer')");
 $peer->close();
 $conn->query("SELECT GET_LOCK('bot_worker',0)");
 check(!isset($indexer->run(true)['busy']),'indexer does not acquire bot tick lock');
 $conn->query("SELECT RELEASE_LOCK('bot_worker')");
 $ids[]=semanticRow($conn,$user,'DL-6 '.$marker);
 $memoryId=(int)(new Domain\BotMemoryManager())->add('dossier',$user,'DL-6 '.$marker,'manual',$user);
 $before=(int)$conn->query('SELECT COUNT(*) FROM memory_episodes')->fetch_row()[0];
 $dry=$indexer->run(true);
 check($dry['episodes']>=2&&count($fake->texts)===0&&$associationCalls===0,'dry run builds chat and memory without API');
 check((int)$conn->query('SELECT COUNT(*) FROM memory_episodes')->fetch_row()[0]===$before,'dry run makes no index writes');
 $one=$indexer->run(false,1,microtime(true)+30);
 check($one['elements']===1 && $one['association_calls']===1 && $one['embedding_calls']===1,'one element allows two distinct API calls');
 check($one['http_attempts']===0 && !$one['deadline_stopped'],'fake APIs make no real HTTP attempts');
 $result=$indexer->run(false,100,microtime(true)+30);
 check($result['errors']===0&&$one['ready']+$result['ready']>=2,'chat and bot_memory become ready');
 check(str_contains(implode(' ',$fake->texts),'Ace Attorney'),'association tags enter embedding input');
 check(count($store->findAssociationIds('Attorney'))>=2,'association tags enter real FULLTEXT search');
 $calls=count($fake->texts);$count=(int)$conn->query('SELECT COUNT(*) FROM memory_episodes')->fetch_row()[0];
 $indexer->run(false,100,microtime(true)+30);
 check(count($fake->texts)===$calls&&(int)$conn->query('SELECT COUNT(*) FROM memory_episodes')->fetch_row()[0]===$count,'restart and repeat neither duplicate episodes nor repeat ready API calls');
 // Rollback restores both source state and derived index state.
 $readyBefore=$conn->query('SELECT id,revision,status,associations,embedding FROM memory_episodes ORDER BY id')->fetch_all(MYSQLI_ASSOC);
 try {
  Infra\Transaction::run($conn,function()use($conn,$ids,$user){
   (new Domain\ChatManager())->editMessage($ids[0],$user,'rolled back');
   throw new RuntimeException('test rollback');
  });
 } catch (RuntimeException $error) {}
 check($conn->query('SELECT id,revision,status,associations,embedding FROM memory_episodes ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$readyBefore,'source rollback restores derived vectors and revision');
 // Failed association generation makes no embedding attempt and can resume.
 $failedAssociations=new MemoryAssociations(fn()=>null,'failing-model');
 $callsBefore=count($fake->texts);
 $failed=(new MemoryIndexer($store,$builder,$fake,$failedAssociations))->run(false,100,microtime(true)+30);
 check($failed['errors']>=2&&count($fake->texts)===$callsBefore,'association failure leaves pending sources without embedding API');
 $resumed=$indexer->run(false,100,microtime(true)+30);
 check($resumed['ready']>=2,'changing back to a valid profile resumes indexing');
 // Public source entry points invalidate immediately, even within the same timestamp second.
 $chat=new Domain\ChatManager();
 check($chat->editMessage($ids[0],$user,'Изменённое DL-6 '.$marker),'public edit succeeds');
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episodes e JOIN memory_episode_sources s ON e.id=s.episode_id WHERE s.source_kind='chat' AND s.source_id={$ids[0]} AND e.status='ready'")->fetch_row()[0]===0,'edit invalidates all overlapping ready episodes');
 $indexer->run(false,100,microtime(true)+30);
 check($chat->deleteMessage($ids[0],$user),'public delete succeeds');
 $result=$indexer->run(false,100,microtime(true)+30);
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episode_sources WHERE source_kind='chat' AND source_id={$ids[0]}")->fetch_row()[0]===0,'deleted source removed by reconciliation');
 check($chat->restoreMessage($ids[0],$user),'public restore succeeds');
 $result=$indexer->run(false,100,microtime(true)+30);
 check($result['ready']>=1,'restored source is rebuilt');
 $chat->editMessage($ids[0],$user,'association race');
 $callsBefore=count($fake->texts);
 $editingAssociations=new MemoryAssociations(function($text,$prompt,$deadline)use($chat,$ids,$user){
  $chat->editMessage($ids[0],$user,'edited during association');
  return 'Ace Attorney';
 },'association-race');
 $associationRace=(new MemoryIndexer($store,$builder,$fake,$editingAssociations))->run(false,100,microtime(true)+30);
 check($associationRace['stale']>=1,'source edit during association invalidates result before embedding');
 $indexer->run(false,100,microtime(true)+30);
 // A delayed API response races with an edit on the same actual source.
 $chat->editMessage($ids[0],$user,'before race');
 $fake->after=fn()=>$chat->editMessage($ids[0],$user,'after race');
 $result=$indexer->run(false,100,microtime(true)+30);
 check($result['stale']>=1,'late embedding after edit rejected');
 $indexer->run(false,100,microtime(true)+30);
 $chat->editMessage($ids[0],$user,'privacy race');
 $fake->after=fn()=>Domain\Privacy::setAccountConsent($user,false,false);
 $result=$indexer->run(false,100,microtime(true)+30);
 check($result['stale']>=1,'late embedding after withdrawal rejected');
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episode_sources WHERE user_id=$user OR related_user_id=$user")->fetch_row()[0]===0,'withdrawal erases membership, vectors and association metadata');
 $indexer->run(false,100,microtime(true)+30);
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episode_sources WHERE user_id=$user OR related_user_id=$user")->fetch_row()[0]===0,'withdrawn sources cannot be reindexed');
 Domain\Privacy::setAccountConsent($user,true,false);
 $indexer->run(false,100,microtime(true)+30);
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episode_sources WHERE user_id=$user")->fetch_row()[0]>0,'restored consent permits new indexing');
 $memoryManager=new Domain\BotMemoryManager();
 check($memoryManager->updateText($memoryId,'Новая заметка DL-6'),'public memory edit succeeds');
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episodes e JOIN memory_episode_sources s ON e.id=s.episode_id WHERE s.source_kind='memory' AND s.source_id=$memoryId AND e.status='ready'")->fetch_row()[0]===0,'memory edit invalidates vector and tags');
 $indexer->run(false,100,microtime(true)+30);
 $createdByOther=(int)$memoryManager->add('meme',null,'Культурная ассоциация '.$marker,'manual',$second);
 $indexer->run(false,100,microtime(true)+30);
 Domain\Privacy::setAccountConsent($second,false,false);
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episode_sources WHERE related_user_id=$second")->fetch_row()[0]===0,'withdrawal of memory creator removes derived records');
 $memoryManager->delete($createdByOther);
 (new Domain\BotMemoryManager())->eraseUserData($user);
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episode_sources WHERE source_kind='memory' AND source_id=$memoryId")->fetch_row()[0]===0,'bot_memory erasure removes derived metadata');
 (new Domain\ChatManager())->eraseUserData($user);
 (new Domain\UserManager())->deleteUser($user);
 check((int)$conn->query("SELECT COUNT(*) FROM memory_episode_sources WHERE user_id=$user OR related_user_id=$user")->fetch_row()[0]===0,'account erasure removes derived vectors and authors');
}finally{
 foreach($ids as$id)$conn->query('DELETE FROM chat_messages WHERE id='.(int)$id);
 if($memoryId)$conn->query('DELETE FROM bot_memory WHERE id='.$memoryId);
 foreach([$user,$second]as$id){foreach(['privacy_consent_events','privacy_account_choices','privacy_browser_owners','privacy_sessions','privacy_mail_cleanup','privacy_files','account_privacy'] as $table){if($conn->query("SHOW COLUMNS FROM `$table` LIKE 'user_id'")->num_rows)$conn->query('DELETE FROM `'.$table.'` WHERE user_id='.(int)$id);}$conn->query('DELETE FROM users WHERE id='.(int)$id);}
 $cookieHash=Domain\Privacy::tokenHash();
 if($cookieHash){$stmt=$conn->prepare('DELETE FROM privacy_choices WHERE token_hash=?');$stmt->bind_param('s',$cookieHash);$stmt->execute();}
 // Only derived fixtures from this isolated integration corpus remain after source cleanup.
 $store->prune([]);
}
it_done();
