<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('Requires isolated DB_HOST=db');
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$config = Infra\ConfigManager::getInstance();
$old = $config->getOption('bot_last_manual_draw', 0);
$oldAuto = $config->getOption('bot_last_autodraw', 0);
$queue = new LLM\JobQueue(); $id = 0; $extraIds = [];
try {
    $config->setOption('bot_last_manual_draw', '0');
    $id = $queue->reserveInline('dynamic_command', ['command'=>['handler_type'=>'image'], 'message'=>'/draw fixture'], 30);
    check($queue->hasManualDrawing(), 'synchronous drawing is visible during its delay');
    $generated = 0;
    LLM\DrawingSchedule::run(true, function () use (&$generated) { $generated++; return true; });
    check($generated === 0, 'automatic generation skips reserved synchronous manual drawing');
    // A stale SELECT result cannot steal the synchronous reservation.
    $result = $db->query('SELECT * FROM llm_jobs WHERE id=' . $id);
    $claimed = (new ReflectionMethod($queue, 'collectAndMark'))->invoke($queue, $result);
    check($claimed === [], 'conditional claim rejects already reserved jobs');
    $queue->complete([$id]);
    $probe = <<<'CODE'
require 'autoload.php';
if (Infra\Env::get('DB_HOST') !== 'db') exit(2);
try { LLM\DrawingSchedule::run(false, function () { echo 'GENERATED'; return true; }); }
catch (LLM\DrawingBusy $error) { echo 'BUSY'; }
CODE;
    $out = LLM\DrawingSchedule::run(false, function () use ($probe) {
        $proc = proc_open([PHP_BINARY, '-r', $probe], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
        fclose($pipes[0]); $text = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($proc) !== 0) throw new RuntimeException($err);
        check($text === 'BUSY', 'separate process cannot enter while manual generation owns the lock');
        return true;
    });
    check($out === true, 'manual execution completes with lock released');
    LLM\DrawingSchedule::manualPublished();
    LLM\DrawingSchedule::run(true, function () use (&$generated) { $generated++; return true; });
    check($generated === 0, 'manual publication starts thirty-minute automatic cooldown');
    check($config->getOptionDetails('bot_last_autodraw')['value'] === $config->getOptionDetails('bot_last_manual_draw')['value'], 'manual publication resets automatic timestamp');
    $queue->releaseProcessing($id, 3);
    check($db->query('SELECT status FROM llm_jobs WHERE id=' . $id)->fetch_assoc()['status'] === 'done', 'finally cannot requeue completed synchronous job');
    $marker = 'arch394_' . bin2hex(random_bytes(6));
    $payload = ['command'=>['handler_type'=>'image'], 'message'=>$marker];
    $before = (int)$db->query('SELECT COALESCE(MAX(id),0) AS n FROM llm_jobs')->fetch_assoc()['n'];
    try {
        (new ReflectionMethod(LLM\BotDispatch::class, 'inlineDrawing'))->invoke(null, $payload, 0, static function () { throw new RuntimeException('post-effect failure'); });
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'post-effect failure', 'inline propagates uncertain post-effect failure');
    }
    $row = $db->query('SELECT id,status FROM llm_jobs WHERE id>' . $before . ' ORDER BY id LIMIT 1')->fetch_assoc();
    $extraIds[] = (int)$row['id'];
    check($row['status'] === 'failed', 'post-effect failure never queues another image generation');
} finally {
    foreach ($extraIds as $extraId) $db->query('DELETE FROM llm_jobs WHERE id=' . $extraId);
    if ($id) $db->query('DELETE FROM llm_jobs WHERE id=' . $id);
    $config->setOption('bot_last_manual_draw', (string)$old); $config->setOption('bot_last_autodraw', (string)$oldAuto);
}
it_done();
