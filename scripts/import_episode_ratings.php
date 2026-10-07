<?php
/** Operator-only bounded import; acquisition happens outside application runtime. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../autoload.php';

function ratingImportArguments(array $args): array
{
    $result=[];
    $values=['file','mapping-file','expected-batch'];
    while ($args) {
        $arg=array_shift($args);
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/D',$arg,$match)) throw new Core\UserError('invalid_cli_argument');
        $key=$match[1];
        if (array_key_exists($key,$result) || !in_array($key,array_merge($values,['apply','dry-run']),true)) throw new Core\UserError('invalid_cli_argument');
        if (in_array($key,$values,true)) {
            $value=$match[2]??array_shift($args);
            if (!is_string($value) || $value==='' || str_starts_with($value,'--')) throw new Core\UserError('missing_cli_value');
            $result[$key]=$value;
        } else {
            if (isset($match[2])) throw new Core\UserError('invalid_cli_flag');
            $result[$key]=true;
        }
    }
    if (empty($result['file']) || empty($result['mapping-file']) || (isset($result['apply'])&&isset($result['dry-run']))) throw new Core\UserError('invalid_cli_arguments');
    return $result;
}

function ratingImportFile(string $path): array
{
    if (!is_file($path) || !is_readable($path) || filesize($path)>1048576) throw new Core\UserError('invalid_or_large_file');
    $data=file_get_contents($path,false,null,0,1048577);
    if ($data===false || strlen($data)>1048576) throw new Core\UserError('invalid_or_large_file');
    try { $value=json_decode($data,true,64,JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new Core\UserError('invalid_json'); }
    if (!is_array($value) || array_is_list($value)) throw new Core\UserError('invalid_json_object');
    return $value;
}

try {
    $args=ratingImportArguments(array_slice($argv,1));
    $input=ratingImportFile($args['file']);$mapping=ratingImportFile($args['mapping-file']);
    $manager=new Domain\EpisodeRatingManager();
    if (isset($args['apply'])) {
        $hash=$args['expected-batch']??'';
        if ($hash!=='none' && preg_match('/^[a-f0-9]{64}$/D',$hash)!==1) throw new Core\UserError('expected_batch_required');
        $report=$manager->apply($input,$mapping,$hash==='none'?null:$hash);
    } else {
        $report=$manager->dryRun($input,$mapping);
    }
    echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    $reason=$error instanceof Core\UserError?$error->getMessage():'import_failed';
    echo json_encode(['status'=>'error','reason'=>substr($reason,0,100)],JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
