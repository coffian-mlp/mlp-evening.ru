<?php
require __DIR__.'/integration_helpers.php';
it_require_db();ob_start();
require_once dirname(__DIR__).'/migrate.php';
$db=Infra\Database::getInstance()->getConnection();
migrate_apply_file($db,dirname(__DIR__).'/migrations/2026_10_16_privacy_account_choices.sql');
$users=new Domain\UserManager();$ids=[];$errors=0;$hashes=[];
$ok=static function(bool $condition,string $label)use(&$errors){echo($condition?'[OK] ':'[FAIL] ').$label."\n";if(!$condition)$errors++;};
try{
    $suffix=bin2hex(random_bytes(6));
    foreach(['first','second'] as $key)$ids[$key]=$users->createUser('it_choices_'.$key.'_'.$suffix,'Aa!'.bin2hex(random_bytes(12)));
    unset($_SESSION['user_id'],$_COOKIE[Domain\Privacy::COOKIE]);
    Domain\Privacy::saveChoices(true,false);$guestCookie=$_COOKIE[Domain\Privacy::COOKIE];$hashes[]=Domain\Privacy::tokenHash();
    $_SESSION['user_id']=$ids['first'];
    $ok(Domain\Privacy::choices()===['decided'=>true,'analytics'=>true,'external_media'=>false],'An explicit guest choice is adopted by the newly authenticated account');
    $ok(!Domain\Privacy::isActive($ids['first']),'Optional preferences never fabricate separate account data consent');
    unset($_COOKIE[Domain\Privacy::COOKIE]);
    $ok(Domain\Privacy::choices()===['decided'=>true,'analytics'=>true,'external_media'=>false],'A fresh device receives the account choice without another prompt');$hashes[]=Domain\Privacy::tokenHash();
    $firstCookie=$_COOKIE[Domain\Privacy::COOKIE];
    $_SESSION['user_id']=$ids['second'];
    $ok(!Domain\Privacy::choices()['decided'],'A different account does not inherit another account choice on a shared device');
    Domain\Privacy::saveChoices(false,true);$hashes[]=Domain\Privacy::tokenHash();
    $ok($_COOKIE[Domain\Privacy::COOKIE]!==$firstCookie,'Switching accounts creates a separate browser consent identifier');
    $_SESSION['user_id']=$ids['first'];unset($_COOKIE[Domain\Privacy::COOKIE]);
    $ok(Domain\Privacy::choices()['analytics']&&!Domain\Privacy::choices()['external_media'],'Other account preferences cannot overwrite the first account');$hashes[]=Domain\Privacy::tokenHash();
    $db->query("UPDATE privacy_account_choices SET policy_version='historical' WHERE user_id=".$ids['first']);
    $ok(Domain\Privacy::choices()['decided'],'Optional preferences do not reset on a version or operator change');
    Domain\Privacy::saveChoices(false,false);
    $_COOKIE[Domain\Privacy::COOKIE]=$guestCookie;
    $ok(Domain\Privacy::choices()===['decided'=>true,'analytics'=>false,'external_media'=>false],'Account opt-out supersedes a stale device cookie');
    $pending=['state'=>null];
    foreach(['get_messages','get_public_events','get_packs','heartbeat','get_users'] as $action)$ok(Domain\Privacy::actionAllowed($action,$pending),'Pending consent allows read route '.$action);
    foreach(['send_message','catalogue_wish','update_settings','privacy_file_review'] as $action)$ok(!Domain\Privacy::actionAllowed($action,$pending),'Pending consent prevents mutation '.$action);
    Domain\Privacy::setAccountConsent($ids['first'],true);Domain\Privacy::setAccountConsent($ids['first'],false);
    $withdrawn=Domain\Privacy::account($ids['first']);
    $ok(Domain\Privacy::actionAllowed('get_messages',$withdrawn)&&Domain\Privacy::actionAllowed('get_public_events',$withdrawn)&&!Domain\Privacy::actionAllowed('get_users',$withdrawn),'Withdrawal preserves public reading without exposing administration');
    Domain\Privacy::stageErasure($ids['first'],[]);
    $ok(!$db->query('SELECT 1 FROM privacy_account_choices WHERE user_id='.$ids['first'])->num_rows&&!$db->query('SELECT 1 FROM privacy_browser_owners WHERE user_id='.$ids['first'])->num_rows,'Erasure removes permanent account choices and all browser ownership links');
    $_SESSION['user_id']=$ids['second'];unset($_COOKIE[Domain\Privacy::COOKIE]);
    $ok(Domain\Privacy::choices()===['decided'=>true,'analytics'=>false,'external_media'=>true],'Erasure leaves the other account preferences intact');$hashes[]=Domain\Privacy::tokenHash();
}finally{
    unset($_SESSION['user_id']);
    foreach($ids as $id){
        $db->query('DELETE FROM privacy_choices WHERE token_hash IN (SELECT token_hash FROM privacy_browser_owners WHERE user_id='.(int)$id.')');
        foreach(['privacy_browser_owners','privacy_account_choices','account_privacy','privacy_consent_events'] as $table)$db->query("DELETE FROM $table WHERE user_id=".(int)$id);
        $users->deleteUser($id);
    }
    foreach(array_unique($hashes) as $hash){$stmt=$db->prepare('DELETE FROM privacy_choices WHERE token_hash=?');$stmt->bind_param('s',$hash);$stmt->execute();}
}
echo $errors?"FAIL: $errors\n":"ALL PASS\n";exit($errors?1:0);
