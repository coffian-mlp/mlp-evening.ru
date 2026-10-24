<?php
/** CLI-only atomic catalogue extension; acquisition is performed separately. */
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/autoload.php';

try {
    $args=[];$remaining=array_slice($argv,1);
    while ($remaining) {
        $arg=array_shift($remaining);
        if (!preg_match('/^--(file|expected-catalog|expected-batch|apply|dry-run)$/D',$arg,$match) || isset($args[$match[1]])) throw new Core\UserError('invalid_cli_argument');
        $key=$match[1];
        if (in_array($key,['apply','dry-run'],true)) {$args[$key]=true;continue;}
        $value=array_shift($remaining);
        if (!is_string($value) || $value==='' || str_starts_with($value,'--')) throw new Core\UserError('missing_cli_value');
        $args[$key]=$value;
    }
    if (empty($args['file']) || (isset($args['apply'])&&isset($args['dry-run']))) throw new Core\UserError('invalid_cli_arguments');
    $path=$args['file'];
    if (!is_file($path)||!is_readable($path)||filesize($path)>1048576) throw new Core\UserError('invalid_or_large_file');
    $raw=file_get_contents($path,false,null,0,1048577);
    if ($raw===false||strlen($raw)>1048576) throw new Core\UserError('invalid_or_large_file');
    $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($input)||array_diff(array_keys($input),['schema_version','entries'])||($input['schema_version']??null)!==1||!is_array($input['entries']??null)) throw new Core\UserError('invalid_catalogue_file');
    $report=(new Domain\EpisodeRatingManager())->extendCatalogue($input['entries'],isset($args['apply']),$args['expected-catalog']??null,$args['expected-batch']??null);
    echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['status'=>'error','reason'=>$error instanceof Core\UserError?$error->getMessage():'catalogue_import_failed'],JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
