<?php
require __DIR__ . '/integration_helpers.php';
it_require_db();
ob_start();
$db=Infra\Database::getInstance()->getConnection();
require_once dirname(__DIR__).'/migrate.php';
migrate_apply_file($db,dirname(__DIR__).'/migrations/2026_10_16_privacy_statistics.sql');
migrate_apply_file($db,dirname(__DIR__).'/migrations/2026_10_16_privacy_account_choices.sql');
$users=new Domain\UserManager(); $ids=[]; $messages=[]; $errors=0; $testFiles=[]; $testJobs=[]; $campaignId=0; $mailOriginal=null;
$ok=static function(bool $condition,string $message) use (&$errors): void {echo ($condition?'[OK] ':'[FAIL] ').$message."\n";if(!$condition)$errors++;};
try {
    $suffix=bin2hex(random_bytes(6));
    foreach (['owner','other'] as $role) $ids[$role]=$users->createUser('it_privacy_'.$role.'_'.$suffix,'Aa!'.bin2hex(random_bytes(12)));
    $owner=$ids['owner'];$other=$ids['other'];
    $mailPath=dirname(__DIR__).'/logs/mail.log';$mailOriginal=is_file($mailPath)?file_get_contents($mailPath):false;
    $email='privacy_'.$suffix.'@example.test';$users->updateUser($owner,['email'=>$email]);
    $entry=static fn($recipient,$body)=>str_repeat('=',50)."\nDate: ".gmdate('Y-m-d H:i:s')."\nTo: $recipient\nSubject: Fixture\nBody:\n$body\n".str_repeat('=',50)."\n\n";
    file_put_contents($mailPath,($mailOriginal?:'').$entry($email,'OWN_MAIL_'.$suffix).$entry('other_'.$suffix.'@example.test','OTHER_MAIL_'.$suffix),LOCK_EX);
    foreach(['owned','legacy','foreign'] as $kind){$testFiles[$kind]='/upload/chat/privacy_'.$kind.'_'.$suffix.'.txt';file_put_contents(dirname(__DIR__).$testFiles[$kind],$kind);}
    Domain\Privacy::registerUpload($testFiles['owned'],$owner);Domain\Privacy::registerUpload($testFiles['foreign'],$other);
    Domain\Privacy::rememberSession($owner);$fixtureSession=session_id();
    $ok(!Domain\Privacy::isActive($owner),'Legacy/new accounts have no fabricated consent');
    Domain\Privacy::setAccountConsent($owner,true);
    $ok(Domain\Privacy::isActive($owner),'Separate explicit consent activates the account');
    $state=Domain\Privacy::account($owner);
    $ok(Domain\Privacy::actionAllowed('send_message',$state),'Active account may use chat');
    $text='Private body '.$suffix.' '.implode(' ',$testFiles).' /upload/chat/.htaccess';
    $stmt=$db->prepare("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES (?,'Owner',?,UTC_TIMESTAMP())");$stmt->bind_param('is',$owner,$text);$stmt->execute();$messages[]=$ownMessage=(int)$db->insert_id;
    $db->query("INSERT INTO chat_messages(user_id,username,message,quoted_msg_ids,created_at) VALUES ($other,'Other','Keep other content',JSON_ARRAY($ownMessage),UTC_TIMESTAMP())");$messages[]=$otherMessage=(int)$db->insert_id;
    $queue=new LLM\JobQueue();
    $testJobs[]=$ownedJob=$queue->enqueue('dynamic_command',['user_id'=>$owner,'message'=>'own request'],0);
    $testJobs[]=$confirmationJob=$queue->enqueue('dynamic_command',['confirmation'=>['quotes'=>[$ownMessage]],'message'=>'own confirmation'],0);
    $testJobs[]=$unrelatedJob=$queue->enqueue('dynamic_command',['user_id'=>$other,'message'=>'ordinary identity request'],0);
    $users->updateUser($owner,['nickname'=>'i']);
    $episode=(int)$db->query('SELECT MIN(ID) id FROM episode_list')->fetch_assoc()['id'];
    $db->query("INSERT INTO episode_wishes(user_id,episode_id,status,accepted_at,updated_at) VALUES ($owner,$episode,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $facts=json_encode(['chat'=>[['id'=>$ownMessage,'text'=>'Private evidence'],['id'=>$otherMessage,'text'=>'Other evidence']]]);
    $run='privacy-'.$suffix;$stmt=$db->prepare('INSERT INTO telegram_announcement_campaigns(run_id,stream_number,start_at,fingerprint,facts_json) VALUES (?,999999,UTC_TIMESTAMP(),?,?)');$stmt->bind_param('sss',$run,$suffix,$facts);$stmt->execute();$campaignId=(int)$db->insert_id;
    $db->query("INSERT INTO reminders(user_id,username,text,remind_at) VALUES ($owner,'Owner','private reminder',UTC_TIMESTAMP()+INTERVAL 1 DAY)");
    $db->query("INSERT INTO bot_memory(kind,user_id,text,source,created_by) VALUES ('dossier',$owner,'Private memory','manual',$owner)");
    Domain\Privacy::saveChoices(true,false);
    $_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (Windows NT 10.0) Chrome/140.0.0.0 Safari/537.36';
    $page=bin2hex(random_bytes(16));
    $measurement=['page_id'=>$page,'path'=>'/reset_password.php?token=SECRET','width'=>'1920','height'=>'1080','screen_w'=>'2560','screen_h'=>'1440','density'=>'1.25','font_scale'=>'110','theme'=>'luna','load_ms'=>'1234'];
    $ok(Domain\UxStatistics::record($measurement),'Opted-in browser can submit an initial view');
    Domain\UxStatistics::record($measurement+['chat_error'=>'1']);
    $storedPage=substr(hash('sha256',$page.$_SESSION['ux_visit']['id']),0,32);
    $row=$db->query("SELECT * FROM ux_pageviews WHERE page_id='$storedPage'")->fetch_assoc();
    $ok($row['path']==='Другая страница' && !str_contains(json_encode($row),'SECRET'),'No raw query string or token retained');
    $ok($row['browser']==='Chrome 140' && $row['viewport']==='1900 × 1100','Browser and coarse dimensions are normalized');
    $ok((int)$db->query("SELECT COUNT(*) n FROM ux_pageviews WHERE page_id='$storedPage'")->fetch_assoc()['n']===1,'Resize/retry does not inflate page views');
    $db->begin_transaction();
    try {
        $oldDay=gmdate('Y-m-d',time()-30*86400);
        $before=(int)($db->query("SELECT views FROM ux_daily WHERE day='$oldDay' AND dimension='overall'")->fetch_assoc()['views']??0);
        $db->query("UPDATE ux_pageviews SET created_at='$oldDay 12:00:00' WHERE page_id='$storedPage'");
        Domain\UxStatistics::maintain();
        $ok(!$db->query("SELECT 1 FROM ux_pageviews WHERE page_id='$storedPage'")->num_rows,'Old detailed measurements are removed only after aggregation');
        $ok((int)$db->query("SELECT views FROM ux_daily WHERE day='$oldDay' AND dimension='overall'")->fetch_assoc()['views']===$before+1,'Daily aggregate retains the page after raw data expires');
    } finally {$db->rollback();}
    Domain\Privacy::saveChoices(false,false);
    $ok(!Domain\UxStatistics::record($measurement),'Server refuses measurements after opt-out');
    $ok(!(bool)$db->query("SELECT 1 FROM ux_pageviews WHERE page_id='$storedPage'")->num_rows,'Opt-out deletes attributable raw measurements');
    $ok(Domain\Privacy::isActive($owner),'Analytics refusal does not deactivate account');

    Domain\Privacy::setAccountConsent($owner,false);
    $ok((int)$db->query("SELECT delete_pending FROM privacy_files WHERE path='".$testFiles['legacy']."'")->fetch_assoc()['delete_pending']===2,'Unattributed legacy URLs require review, not arbitrary unlink');
    $ok(!$db->query("SELECT 1 FROM privacy_files WHERE path='/upload/chat/.htaccess'")->num_rows,'Message links cannot enqueue system dotfiles for deletion');
    $withdrawn=Domain\Privacy::account($owner);
    Domain\AccountErasure::maintain();
    $mailNow=file_get_contents($mailPath);
    $ok(!str_contains($mailNow,'OWN_MAIL_'.$suffix)&&str_contains($mailNow,'OTHER_MAIL_'.$suffix),'Cleanup removes only attributable recovery copies, preserving unrelated mail history');
    $ok(!Domain\Privacy::payloadAllowed(['user_id'=>$owner]) && !Domain\Privacy::payloadAllowed(['confirmation'=>['quotes'=>[$ownMessage]]]),'Queued actor and source-bound confirmations are blocked after withdrawal');
    $ok($db->query("SELECT status FROM episode_wishes WHERE user_id=$owner")->fetch_assoc()['status']==='privacy_suspended','Withdrawn preferences do not affect future playlist selection');
    $ok(!Domain\Privacy::actionAllowed('send_message',$withdrawn) && !Domain\Privacy::actionAllowed('vote_poll',$withdrawn) && Domain\Privacy::actionAllowed('update_profile',$withdrawn),'Withdrawn account only retains profile/auth/privacy operations');
    $deadline=$withdrawn['delete_after'];Domain\Privacy::setAccountConsent($owner,false);
    $ok(Domain\Privacy::account($owner)['delete_after']===$deadline,'Repeated withdrawal cannot postpone erasure');
    $ok($db->query("SELECT status FROM reminders WHERE user_id=$owner")->fetch_assoc()['status']==='cancelled','Withdrawal cancels pending reminders using a valid ENUM value');
    $chat=new Domain\ChatManager();$hidden=$chat->getMessageById($ownMessage);
    $ok(!str_contains(json_encode($hidden),'Private body') && !empty($hidden['is_deleted']),'Public/API/LLM message view redacts withdrawn content');
    $ok((new Domain\BotMemoryManager())->getByUser($owner)===[],'Withdrawn dossier is not returned');
    $ok(!Domain\AccountErasure::eraseDue($owner),'Erasure cannot run before the due date');
    Domain\Privacy::setAccountConsent($owner,true);
    $ok($db->query("SELECT status FROM episode_wishes WHERE user_id=$owner")->fetch_assoc()['status']==='active','Renewal restores suspended episode wishes');
    $ok(Domain\Privacy::isActive($owner) && str_contains(json_encode($chat->getMessageById($ownMessage)),'Private body'),'Renewal restores activity and existing content');
    Domain\Privacy::setAccountConsent($owner,false);
    $db->query("UPDATE account_privacy SET delete_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE user_id=$owner");
    $denied=false;try{Domain\Privacy::setAccountConsent($owner,true);}catch(Core\UserError){$denied=true;}
    $ok($denied,'Renewal cannot postpone erasure after the recovery deadline');
    $ok(Domain\AccountErasure::eraseDue($owner),'Due account is erased');
    $ok($users->getUserById($owner)===null,'Deleted account cannot authenticate via a surviving user row');
    $ok(!$db->query("SELECT 1 FROM llm_jobs WHERE id IN ($ownedJob,$confirmationJob)")->num_rows,'Actor jobs and source-linked durable replies are erased');
    $ok((bool)$db->query("SELECT 1 FROM llm_jobs WHERE id=$unrelatedJob AND status='pending'")->num_rows,'A short nickname cannot cancel unrelated queued commands');
    $remainingFacts=json_decode($db->query("SELECT facts_json FROM telegram_announcement_campaigns WHERE id=$campaignId")->fetch_assoc()['facts_json'],true);
    $ok(count($remainingFacts['chat'])===1 && $remainingFacts['chat'][0]['id']===$otherMessage,'Persisted announcement evidence drops only the erased source; post text is not rewritten');
    $row=$db->query("SELECT * FROM chat_messages WHERE id=$ownMessage")->fetch_assoc();
    $ok($row['user_id']===null && $row['message']==='' && $row['quoted_msg_ids']===null,'Own message retains an identity-free tombstone');
    $row=$db->query("SELECT * FROM chat_messages WHERE id=$otherMessage")->fetch_assoc();
    $ok($row['message']==='Keep other content' && (int)$row['user_id']===$other,'Other authors content and quote IDs remain intact');
    Domain\AccountErasure::removeFiles();Domain\AccountErasure::removeSessions();
    $ok(!is_file(dirname(__DIR__).$testFiles['owned']) && is_file(dirname(__DIR__).$testFiles['foreign']),'Only attributed owned uploads are automatically removed');
    $ok(is_file(dirname(__DIR__).$testFiles['legacy']),'Unverified legacy uploads are preserved for operator review');
    Domain\Privacy::decideFile($testFiles['legacy'],true);Domain\AccountErasure::removeFiles();
    $ok(!is_file(dirname(__DIR__).$testFiles['legacy']),'Explicit operator attribution completes pending legacy erasure');
    $stmt=$db->prepare('SELECT 1 FROM privacy_sessions WHERE session_id=?');$stmt->bind_param('s',$fixtureSession);$stmt->execute();
    $ok(!$stmt->get_result()->num_rows,'Known PHP session mappings are removed with their files');
    foreach (['account_privacy','privacy_consent_events','bot_memory','reminders','auth_tokens','user_options','user_socials'] as $table) {
        $ok(!(bool)$db->query("SELECT 1 FROM $table WHERE user_id=$owner")->num_rows,"$table direct user data removed");
    }
    $ok(!Domain\AccountErasure::eraseDue($owner),'Erasure is idempotent');
    $ok(Domain\UxStatistics::size(INF,10)==='Неизвестно','Invalid dimensions cannot create arbitrary labels');
} finally {
    foreach ($messages as $id) $db->query('DELETE FROM chat_messages WHERE id='.(int)$id);
    foreach ($ids as $id) {
        foreach (['account_privacy','privacy_consent_events','reminders','auth_tokens','user_options','user_socials'] as $table) $db->query("DELETE FROM $table WHERE user_id=".(int)$id);
        $db->query('DELETE FROM users WHERE id='.(int)$id);
    }
    $hash=Domain\Privacy::tokenHash();if($hash){$stmt=$db->prepare('DELETE FROM privacy_choices WHERE token_hash=?');$stmt->bind_param('s',$hash);$stmt->execute();}
    foreach($testFiles as $path){if(is_file(dirname(__DIR__).$path))unlink(dirname(__DIR__).$path);$stmt=$db->prepare('DELETE FROM privacy_files WHERE path=?');$stmt->bind_param('s',$path);$stmt->execute();}
    foreach($testJobs as $id)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$id);
    if($campaignId)$db->query('DELETE FROM telegram_announcement_campaigns WHERE id='.(int)$campaignId);
    if($mailOriginal!==null){if($mailOriginal===false){if(is_file($mailPath))unlink($mailPath);}else file_put_contents($mailPath,$mailOriginal,LOCK_EX);}
    $stmt=$db->prepare('DELETE FROM privacy_mail_cleanup WHERE email=?');$stmt->bind_param('s',$email);$stmt->execute();
}
echo $errors?"FAIL: $errors\n":"ALL PASS\n";exit($errors?1:0);
