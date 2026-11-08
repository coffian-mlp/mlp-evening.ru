<?php
ob_start();require_once __DIR__.'/integration_helpers.php';it_require_db()->close();$db=Infra\Database::getInstance()->getConnection();
require_once dirname(__DIR__).'/migrate.php';migrate_database($db,dirname(__DIR__).'/migrations');
use Domain\BotMemoryManager;use Domain\MemoryEpisodeManager;
$marker='it_archive_'.bin2hex(random_bytes(6));$bm=new BotMemoryManager();$uid=0;
$db->begin_transaction();
try{
 $uid=(new Domain\UserManager())->createUser($marker,'Aa!'.bin2hex(random_bytes(12)),'user','Archive Fixture');
 $oldText=$marker.' Старый трёхлитровый попугай DL-6';$otherText=$marker.' Другая архивная легенда';$summary=$marker.' Краткая легенда';$manualText=$marker.' Ручная легенда';
 $old=$bm->add('meme',null,$oldText,'auto',$uid);$other=$bm->add('meme',null,$otherText,'auto',$uid);$manual=$bm->add('meme',null,$manualText,'manual',$uid);
 $oldRow=$db->query('SELECT * FROM bot_memory WHERE id='.(int)$old)->fetch_assoc();
 $profile=hash('sha256',$marker);$reader=new LLM\MemorySourceReader();$store=new MemoryEpisodeManager();$episode=(new LLM\MemoryEpisodeBuilder(2))->memory([$oldRow],$profile)[0];$record=$store->register($episode,$reader->current(...));$vector=array_fill(0,512,0.0);$vector[0]=1;$store->publish($episode,$record,'трёхлитровый попугай DL-6',$vector,$reader->current(...));
 check($bm->replaceAutoMemes([$summary,$summary,$manualText]),'compression succeeds with duplicate compact lines');
 $archived=$db->query('SELECT * FROM bot_memory WHERE id='.(int)$old)->fetch_assoc();
 check($archived!==null&&(int)$archived['is_archived']===1,'old auto meme is archived instead of deleted');
 foreach(['id','text','created_by','created_at','updated_at']as$field)check($archived[$field]===$oldRow[$field],'archive preserves '.$field);
 check((int)$db->query("SELECT COUNT(*) FROM bot_memory WHERE text='".$db->real_escape_string($summary)."'")->fetch_row()[0]===1,'duplicate compact output creates one row');
 check((int)$db->query("SELECT COUNT(*) FROM bot_memory WHERE text='".$db->real_escape_string($manualText)."'")->fetch_row()[0]===1,'compact output does not duplicate manual memory');
 check(!in_array($old,array_map('intval',array_column($bm->getMemes(),'id')),true),'permanent meme reader excludes archive');
 check(!in_array($old,array_map('intval',array_column($bm->getPage(200,0,'meme')['items'],'id')),true),'legacy page excludes archive by default');
 check(in_array($old,array_map('intval',array_column($bm->getPage(200,0,'meme',true)['items'],'id')),true),'explicit admin page includes archive');
 $active=$db->query("SELECT COALESCE(SUM(CHAR_LENGTH(text)),0) FROM bot_memory WHERE kind='meme' AND source='auto' AND is_archived=0")->fetch_row()[0];check($bm->autoMemesLength()===(int)$active,'compression length excludes archive');
 $llm=new LLM\LLMManager();$block=(new LLM\LyraMemory($llm))->buildPromptBlock([],true,$oldText);check(!str_contains((string)$block,$oldText),'permanent prompt excludes archived text');
 check(in_array($old,array_map('intval',array_column($bm->memorySourceRows(),'id')),true),'index source interface includes archive');
 $ready=$store->recallRecords([(int)$record['id']],$profile);check(isset($ready[(int)$record['id']]),'archival metadata does not invalidate ready vector');
 $found=(new LLM\MemoryRetriever($store,$reader,$profile,2,fn()=>['vector'=>$vector,'tags'=>'']))->recall([['role'=>'user','content'=>'DL-6']], 'DL-6',8000);
 check(str_contains(json_encode($found['context'],JSON_UNESCAPED_UNICODE),'chat:')===false&&str_contains(json_encode($found['context']),'memory:'.$old),'real search hydrates archived meme');
 check($bm->add('meme',null,"  ".$otherText."  ",'auto')===$other,'automatic extraction reuses normalized archived text');
 check((int)$db->query('SELECT is_archived FROM bot_memory WHERE id='.(int)$other)->fetch_row()[0]===1,'repeated extraction does not silently unarchive memory');
 check($bm->replaceAutoMemes([$summary,$oldText]),'compact identical archive can reuse existing auto row');
 check((int)$db->query('SELECT is_archived FROM bot_memory WHERE id='.(int)$old)->fetch_row()[0]===0,'selected compact text reuses existing ID without a duplicate');
 $restore=[['id'=>1,'kind'=>'meme','source'=>'auto','text'=>$otherText,'created_by'=>null,'created_at'=>'2026-11-07 10:00:00','updated_at'=>'2026-11-07 10:00:00'],['id'=>2,'kind'=>'meme','source'=>'auto','text'=>$marker.' Новая восстановленная легенда','created_by'=>null,'created_at'=>'2026-11-07 10:00:00','updated_at'=>'2026-11-07 10:00:00']];
 $first=$bm->restoreArchivedMemes($restore);$second=$bm->restoreArchivedMemes($restore);check($first['inserted']===1&&$first['skipped']===1&&$second['inserted']===0&&$second['skipped']===2,'restore is idempotent against active and archived texts');
 $before=(int)$db->query('SELECT COUNT(*) FROM bot_memory')->fetch_row()[0];$dry=$bm->restoreArchivedMemes([array_replace($restore[0],['text'=>$marker.' Dry run only'])],true);check($dry['would_insert']===1&&(int)$db->query('SELECT COUNT(*) FROM bot_memory')->fetch_row()[0]===$before,'restore preview has no writes');
 $bm->replaceAutoMemes([$summary]);
 Domain\Privacy::setAccountConsent($uid,false,false);$hidden=(new LLM\MemoryRetriever($store,$reader,$profile,2,fn()=>['vector'=>$vector,'tags'=>'']))->recall([['role'=>'user','content'=>'DL-6']],'DL-6',8000);check($hidden['metadata']['sources']===[],'withdrawn creator cannot be recalled from archive');
 $denied=$bm->restoreArchivedMemes([array_replace($restore[0],['text'=>$marker.' private recovery','created_by'=>$uid])]);check($denied['privacy_skipped']===1&&$denied['inserted']===0,'restore cannot resurrect a withdrawn creator');
 $missing=$bm->restoreArchivedMemes([array_replace($restore[0],['text'=>$marker.' missing creator','created_by'=>2147483000])]);check($missing['privacy_skipped']===1&&$missing['inserted']===0,'restore skips a missing creator');
 Domain\Privacy::setAccountConsent($uid,true,false);$bm->eraseUserData($uid);check((int)$db->query('SELECT COUNT(*) FROM bot_memory WHERE created_by='.(int)$uid)->fetch_row()[0]===0,'privacy erasure deletes active and archived memories');check(!$store->recallRecords([(int)$record['id']],$profile),'privacy erasure deletes derived archive episodes');
 $one=$bm->restoreArchivedMemes([array_replace($restore[0],['text'=>$marker.' Delete archived'])]);$archiveId=(int)reset($one['ids']);check($bm->delete($archiveId)!==null&&$bm->delete($archiveId)===null,'archive uses ordinary deletion and absent-row behavior');
}finally{$db->rollback();}
// A concurrent automatic writer must recheck the same normalized text after the lock.
$text=$marker.' concurrent';$db->begin_transaction();$id=$bm->add('meme',null,$text,'auto');
$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/helpers/memory_archive_writer.php',$text],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
try{usleep(150000);check(proc_get_status($process)['running'],'second writer waits for the meme range lock');$db->commit();$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$process=null;check($exit===0&&(int)trim($output)===$id,'concurrent writer reuses existing meme after commit');$s=$db->prepare('SELECT COUNT(*) FROM bot_memory WHERE text=?');$s->bind_param('s',$text);$s->execute();check((int)$s->get_result()->fetch_row()[0]===1,'concurrent exact duplicates are not inserted');}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}$bm->delete($id);}
it_done();
