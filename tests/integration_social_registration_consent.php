<?php
require __DIR__.'/integration_helpers.php';
it_require_db();ob_start();
$db=Infra\Database::getInstance()->getConnection();$users=new Domain\UserManager();$id=null;$other=null;$errors=0;
$login='itconsent'.bin2hex(random_bytes(6));
$provider=new class($login) implements Social\SocialProvider{
    public function __construct(private string $login){}
    public function getName():string{return 'telegram';}
    public function validateCallback(array $data):?array{return !empty($data['valid'])?['id'=>$this->login,'username'=>$this->login,'first_name'=>'Registration fixture','last_name'=>'','photo_url'=>null]:null;}
};
$ok=static function(bool $condition,string $label)use(&$errors){echo($condition?'[OK] ':'[FAIL] ').$label."\n";if(!$condition)$errors++;};
try{
    Domain\Auth::check();$_SESSION=[];$service=new Social\SocialAuthService();
    $result=$service->handleLogin($provider,['valid'=>true]);
    $ok(!$result['success']&&!empty($result['registration_consent_required']),'New social identity requests explicit registration consent');
    $ok(!$users->getUserByLogin($login)&&!$service->isUidBound('telegram',$login)&&!Domain\Auth::userId(),'No user, social binding or authenticated session exists before consent');
    $result=$service->handleLogin($provider,['valid'=>true],'outdated');
    $ok(!$result['success']&&!$users->getUserByLogin($login),'An outdated policy version cannot create a profile');
    $result=$service->handleLogin($provider,[],Domain\Privacy::version());
    $ok(!$result['success']&&!$users->getUserByLogin($login),'Consent cannot bypass provider signature validation');
    $other=$users->createUser($login.'other','Aa!'.bin2hex(random_bytes(12)));
    $_SESSION=['user_id'=>$other,'role'=>'user'];
    $result=$service->handleLogin($provider,['valid'=>true],Domain\Privacy::version());
    $ok(!$result['success']&&!$service->isUidBound('telegram',$login),'A registration confirmation cannot silently bind to an account logged in from another tab');
    $_SESSION=[];
    $result=$service->handleLogin($provider,['valid'=>true],Domain\Privacy::version());$id=Domain\Auth::userId();
    $ok($result['success']&&$id!==null&&Domain\Privacy::isActive($id),'Creation and current account consent complete before login succeeds');
    $account=Domain\Privacy::account($id);
    $ok($account['policy_version']===Domain\Privacy::version()&&$account['accepted_at']!==null,'Consent evidence records the current version and acceptance time');
    $_SESSION=[];
    $result=$service->handleLogin($provider,['valid'=>true]);
    $ok($result['success']&&Domain\Auth::userId()===$id,'Existing linked account logs in without repeating registration consent');
}finally{
    $_SESSION=[];
    $id=$id??($users->getUserByLogin($login)['id']??null);
    if($id){foreach(['account_privacy','privacy_consent_events','privacy_sessions','auth_tokens','user_socials'] as $table)$db->query('DELETE FROM '.$table.' WHERE user_id='.(int)$id);$users->deleteUser($id);}
    if($other)$users->deleteUser($other);
    unset($_COOKIE[Domain\Auth::REMEMBER_COOKIE]);
}
echo $errors?"FAIL: $errors\n":"ALL PASS\n";exit($errors?1:0);
