<?php
require dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')exit(2);
it_require_db();$db=Infra\Database::getInstance()->getConnection();$users=new Domain\UserManager();$packs=new Domain\StickerManager();$path=dirname(__DIR__,2).'/docs/private/mlp409-local.json';
$save=static function($s)use($path){$old=umask(0077);file_put_contents($path,json_encode($s));umask($old);};
if(($argv[1]??'')==='setup'){
 if(is_file($path))throw new RuntimeException('Fixture exists');
 $s=['users'=>[],'packs'=>[],'password'=>bin2hex(random_bytes(24)),'suffix'=>bin2hex(random_bytes(6))];$save($s);
 foreach(['admin','reader'] as $role){$s[$role.'Login']='it_mlp409_'.$role.'_'.$s['suffix'];$s[$role]=$users->createUser($s[$role.'Login'],$s['password'],$role==='admin'?'admin':'user','Attribution '.ucfirst($role));$s['users'][]=$s[$role];$save($s);}
 $s['pack']=$packs->createPack('it409_'.$s['suffix'],'Attribution fixture',null,'Author <safe>','https://example.org/artist');$s['packs'][]=$s['pack'];$save($s);
 $s['plain']=$packs->createPack('it409_plain_'.$s['suffix'],'No attribution fixture');$s['packs'][]=$s['plain'];$save($s);
 $packs->addSticker('it409_'.$s['suffix'],'/assets/img/default-avatar.png',$s['pack']);
 $text=htmlspecialchars("Font fixture **bold** `code`\n> Quoted fixture",ENT_QUOTES,'UTF-8');$q=$db->prepare("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,'Attribution Reader',?,UTC_TIMESTAMP())");$q->bind_param('is',$s['reader'],$text);$q->execute();$s['message']=$db->insert_id;$save($s);
}elseif(($argv[1]??'')==='cleanup'){
 $s=json_decode(file_get_contents($path),true);
 foreach($db->query("SELECT id,code FROM sticker_packs WHERE code LIKE 'it409_%'")->fetch_all(MYSQLI_ASSOC) as $p){if(str_ends_with($p['code'],$s['suffix']))$packs->deletePack((int)$p['id']);}
 foreach($s['users'] as $id){if(!str_starts_with($users->getUserById($id)['login']??'','it_mlp409_'))throw new RuntimeException('Invalid user');$db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);$users->deleteUser($id);}
 unlink($path);
}else throw new RuntimeException('Invalid action');
echo "PASS\n";
