<?php
require dirname(__DIR__).'/helpers/drawing_transport.php';
require dirname(__DIR__).'/helpers/drawing_fixture.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/_fixture'){
    header('Content-Type: application/json');
    $action=$_GET['action']??'';
    if($action==='setup'){
        $state=drawingFixture('setup');echo json_encode(['login'=>$state['ownerLogin'],'password'=>$state['password']]);
    }elseif($action==='cleanup'){
        if(is_file(dirname(__DIR__,2).'/cache/mlp468/state.json'))drawingFixture('cleanup');echo '{"ok":true}';
    }elseif($action==='tick'){
        // A cron process has no browser session; keep its HTTP test driver independent of login cookies.
        ini_set('session.use_cookies','0');$_COOKIE=[];session_id('mlp468-worker-'.bin2hex(random_bytes(12)));
        (new LLM\BotWorker())->tick();echo '{"ok":true}';
    }elseif($action==='inspect'){
        echo json_encode(drawingInspect(drawingFixture('inspect')),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }elseif(in_array($action,['block','release'],true)){
        if($action==='block'||is_file(dirname(__DIR__,2).'/cache/mlp468/state.json'))drawingFixture($action);echo '{"ok":true}';
    }elseif($action==='entered'){
        echo json_encode(['entered'=>is_file(dirname(__DIR__,2).'/cache/mlp468/entered')]);
    }elseif($action==='advance'){
        $db=Infra\Database::getInstance()->getConnection();$state=drawingFixture('inspect');
        foreach($db->query('SELECT id,payload FROM llm_jobs WHERE id>'.(int)$state['beforeJob']." AND status='pending'")->fetch_all(MYSQLI_ASSOC)as$row){
            $data=json_decode($row['payload'],true);
            if(isset($data['drawing_chat_window']))$data['drawing_chat_window']['until']=time()-1;
            $json=json_encode($data,JSON_THROW_ON_ERROR);$q=$db->prepare('UPDATE llm_jobs SET payload=?,run_after=UTC_TIMESTAMP() WHERE id=?');$q->bind_param('si',$json,$row['id']);$q->execute();
        }
        echo '{"ok":true}';
    }else{http_response_code(400);echo '{"error":"unknown fixture action"}';}
    return true;
}
if(str_starts_with($path,'/tests/')||str_starts_with($path,'/docs/')||str_starts_with($path,'/cache/')||str_starts_with($path,'/logs/')||str_starts_with($path,'/.')||str_contains($path,'..')||
    (str_starts_with($path,'/src/')&&!preg_match('~^/src/Components/[A-Za-z0-9_./-]+\.(?:js|css|png|svg|webp|woff2?)$~D',$path))){http_response_code(404);return true;}
$file=dirname(__DIR__,2).($path==='/'?'/index.php':$path);
if(str_ends_with($file,'.php')&&is_file($file)){require$file;return true;}
return false;
