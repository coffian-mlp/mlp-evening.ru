<?php
/** Isolated synthetic completion endpoint; never loaded by the production application. */
require_once dirname(__DIR__,2).'/autoload.php';
if(PHP_SAPI!=='cli-server'||!is_file('/.dockerenv')||Infra\Env::get('DB_HOST')!=='db'||Infra\Env::get('DB_NAME')!=='mlp_phase3'||getenv('MLP_CONVERSATION_PW')!=='1'){http_response_code(404);exit;}
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/_fixture'){
 $mode=$_GET['mode']??'';
 if(!in_array($mode,['setup','cleanup','work','inspect','off','reset'],true)){http_response_code(400);exit;}
 $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/mlp-456-conversation-fixture.php',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);foreach($pipes as$pipe)fclose($pipe);$code=proc_close($proc);
 header('Content-Type: application/json');if($code!==0){http_response_code(500);echo '{"fixture_error":true}';}else echo $out;return true;
}
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/_fake_model'){
 $request=json_decode(file_get_contents('php://input'),true);$text=json_encode($request['messages']??[],JSON_UNESCAPED_UNICODE);
 $returned=str_contains($text,LLM\ConversationMemoryPolicy::RETURN_MARKER);$reply=$returned?'Рада снова видеть! Помню нашу шутку про чайник.':'Рада снова видеть!';
 header('Content-Type: application/json');echo json_encode(['choices'=>[['message'=>['content'=>$reply],'finish_reason'=>'stop']],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20,'total_tokens'=>120]],JSON_UNESCAPED_UNICODE);return true;
}
return false;
