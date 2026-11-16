<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db'||(Infra\Env::get('DB_NAME')!=='mlp_phase3'||!is_file(dirname(__DIR__,2).'/.mlp471-owned')))throw new RuntimeException('MLP-471 disposable Docker database required');
it_require_db();$db=Infra\Database::getInstance()->getConnection();$file=dirname(__DIR__,2).'/docs/tests/MLP-471/fixture.json';$mode=$argv[1]??'';
if($mode==='setup'){
    if(is_file($file))throw new RuntimeException('Cleanup previous fixture first');
    $s=['marker'=>'it_mlp471_ui_'.bin2hex(random_bytes(6)),'users'=>[]];
    $save=static function()use(&$s,$file){$mask=umask(0077);file_put_contents($file,json_encode($s,JSON_THROW_ON_ERROR));chmod($file,0600);umask($mask);};$save();
    foreach(['admin','user'] as $role){
        $login=$s['marker'].'_'.$role;$password=bin2hex(random_bytes(20));$id=(new Domain\UserManager())->createUser($login,$password,$role,'Recurrence '.$role);
        $s['users'][$role]=compact('id','login','password');$save();Domain\Privacy::setAccountConsent($id,true);
    }
    echo json_encode($s,JSON_THROW_ON_ERROR);
}elseif($mode==='inspect'){
    $s=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$q=$db->prepare('SELECT * FROM events WHERE title=?');$q->bind_param('s',$s['marker']);$q->execute();echo json_encode($q->get_result()->fetch_all(MYSQLI_ASSOC),JSON_THROW_ON_ERROR);
}elseif($mode==='cleanup'){
    $s=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$q=$db->prepare('SELECT id FROM events WHERE title=?');$q->bind_param('s',$s['marker']);$q->execute();foreach($q->get_result()->fetch_all(MYSQLI_ASSOC)as$r)(new Domain\EventManager())->delete((int)$r['id']);
    foreach($s['users'] as $u){if(((new Domain\UserManager())->getUserById($u['id'])['login']??'')!==$u['login'])throw new RuntimeException('Fixture ownership mismatch');Domain\Privacy::stageErasure($u['id'],[]);(new Domain\UserManager())->deleteUser($u['id']);}
    unlink($file);echo json_encode(['clean'=>true]);
}else throw new RuntimeException('Unknown mode');
