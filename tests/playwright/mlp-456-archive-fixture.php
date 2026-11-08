<?php
require dirname(__DIR__,2).'/autoload.php';
if(!is_file('/.dockerenv')||Infra\Env::get('DB_HOST')!=='db'||Infra\Env::get('DB_NAME')!=='mlp456archive14'||getenv('MLP_ARCHIVE_PW')!=='1')throw new RuntimeException('Disposable archive browser fixture only');
$base=dirname(__DIR__,2).'/docs/tests/MLP-456/archive14';$path=$base.'/pw-state.json';$mode=$argv[1]??'';$db=Infra\Database::getInstance()->getConnection();$bm=new Domain\BotMemoryManager();
if($mode==='setup'){
 if(is_file($path))throw new RuntimeException('Previous fixture requires cleanup');
 $state=['marker'=>'it_archive_pw_'.bin2hex(random_bytes(5)),'password'=>bin2hex(random_bytes(20))];$state['admin_login']=$state['marker'].'_admin';$state['user_login']=$state['marker'].'_user';
 $um=new Domain\UserManager();$state['admin_id']=$um->createUser($state['admin_login'],$state['password'],'admin','Archive Admin');$state['user_id']=$um->createUser($state['user_login'],$state['password'],'user','Archive User');
 foreach([$state['admin_id'],$state['user_id']]as$id){$v=Domain\Privacy::version();$s=$db->prepare("INSERT INTO account_privacy(user_id,state,policy_version,accepted_at) VALUES(?,'active',?,UTC_TIMESTAMP())");$s->bind_param('is',$id,$v);$s->execute();}
 foreach(['ai_enabled'=>0,'ai_debug_log'=>0,'ai_memory_recall_enabled'=>0,'ai_online_in_context'=>0]as$key=>$value)Infra\ConfigManager::getInstance()->setOption($key,$value);
 $text=$state['marker'].' archive <img src=x onerror="document.title=\'ARCHIVE_XSS\'">';
 $added=$bm->restoreArchivedMemes([['id'=>1,'kind'=>'meme','source'=>'auto','text'=>$text,'created_by'=>$state['admin_id'],'created_at'=>'2026-11-07 01:02:03','updated_at'=>'2026-11-07 01:02:03']]);$state['archive_id']=(int)$added['ids'][1];$state['active_id']=$bm->add('meme',null,$state['marker'].' active','manual',$state['admin_id']);
 $row=$bm->memorySourceRows([$state['archive_id']])[0];$profile=hash('sha256',$state['marker']);$reader=new LLM\MemorySourceReader();$store=new Domain\MemoryEpisodeManager();$episode=(new LLM\MemoryEpisodeBuilder(2))->memory([$row],$profile)[0];$record=$store->register($episode,$reader->current(...));$vector=array_fill(0,512,0.0);$vector[0]=1;$store->publish($episode,$record,'synthetic archive fixture',$vector,$reader->current(...));$state['episode_id']=(int)$record['id'];
 umask(0077);file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));echo json_encode($state,JSON_THROW_ON_ERROR);exit;
}
$state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
if($mode==='inspect'){
 $r=$db->query('SELECT id,is_archived,source,text FROM bot_memory WHERE id='.(int)$state['archive_id'])->fetch_assoc();$derived=$db->query('SELECT status FROM memory_episodes WHERE id='.(int)$state['episode_id'])->fetch_assoc();
 $visible=in_array((int)$state['archive_id'],array_map('intval',array_column($bm->getMemes(),'id')),true);
 echo json_encode(['archive'=>$r,'derived'=>$derived,'permanent_contains_archive'=>$visible],JSON_THROW_ON_ERROR);
}elseif($mode==='cleanup'){
 foreach(['archive_id','active_id']as$key)$bm->delete((int)$state[$key]);
 foreach(['admin_id','user_id']as$key){$id=(int)$state[$key];(new Domain\MemoryEpisodeManager())->eraseUser($id);foreach(['privacy_consent_events','privacy_account_choices','privacy_browser_owners','privacy_sessions','privacy_mail_cleanup','privacy_files','account_privacy']as$table)$db->query('DELETE FROM '.$table.' WHERE user_id='.$id);(new Domain\UserManager())->deleteUser($id);}
 unlink($path);echo'{}';
}else throw new RuntimeException('Unknown fixture mode');
