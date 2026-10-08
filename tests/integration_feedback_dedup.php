<?php
/** Isolated scratch-schema tests; never writes to the configured application schema. */
require __DIR__ . '/../autoload.php';
use Domain\FeedbackManager;
use Infra\Env;

if (Env::get('DB_HOST') !== 'db') { echo "SKIP: Docker db host required\n"; exit(0); }
function feedbackConnection(?string $schema = null): mysqli {
    $db = new mysqli('db', 'root', Env::get('DB_ROOT_PASS'), $schema);
    $db->set_charset('utf8mb4');
    return $db;
}
function feedbackOwner(mysqli $db): FeedbackManager {
    $owner = (new ReflectionClass(FeedbackManager::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(FeedbackManager::class, 'db'))->setValue($owner, $db);
    return $owner;
}
if (($argv[1] ?? '') === '--worker') {
    $schema = $argv[2];
    if (!preg_match('/^it_feedback_dedup_[a-f0-9]{12}$/D', $schema)) exit(2);
    $db = feedbackConnection($schema);
    file_put_contents($argv[3], 'ready');
    echo json_encode(($argv[6] ?? '') === 'close'
        ? feedbackOwner($db)->setStatus((int)$argv[5], 'done')
        : feedbackOwner($db)->addOrFind((int)$argv[4], 'worker', null, $argv[5]));
    exit(0);
}
if (getenv('IT_FEEDBACK_SCRATCH') !== '1') {
    echo "SKIP: explicit IT_FEEDBACK_SCRATCH=1 required for isolated schema creation\n";
    exit(0);
}
$fail = 0;
function feedbackCheck(bool $ok, string $label): void {
    global $fail;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $fail++;
}
$schema = 'it_feedback_dedup_' . bin2hex(random_bytes(6));
$admin = feedbackConnection();
$admin->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = feedbackConnection($schema);
$workers = [];
$readyFiles = [];
try {
    $db->query("CREATE TABLE feedback_backlog (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, username VARCHAR(50) NOT NULL,
        message_id INT NULL, text TEXT NOT NULL,
        status ENUM('new','done','dismissed') NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(status),
        CONSTRAINT injected_write_fault CHECK (text <> '__write_fault__')
    ) ENGINE=InnoDB");
    $fm = feedbackOwner($db);
    $first = $fm->addOrFind(10, 'first', 100, 'Починить поиск');
    $original = $db->query('SELECT * FROM feedback_backlog WHERE id=' . $first['id'])->fetch_assoc();
    $same = $fm->addOrFind(20, 'second', 200, " ПОЧИНИТЬ\n\tпоиск ");
    feedbackCheck($first['created'] && !$same['created'] && $same['id'] === $first['id'], 'case/whitespace duplicate across authors returns original ID');
    feedbackCheck($db->query('SELECT * FROM feedback_backlog WHERE id=' . $first['id'])->fetch_assoc() === $original, 'duplicate leaves all original history fields unchanged');
    feedbackCheck($fm->add(30, 'legacy', null, 'починить поиск') === $first['id'], 'legacy add keeps integer ID contract');
    foreach (['не починить поиск', 'починить поиск!', 'улучшить поиск', 'все', 'всё'] as $text) {
        feedbackCheck($fm->addOrFind(10, 'first', null, $text)['created'], 'different text retained: ' . $text);
    }
    foreach (['done', 'dismissed'] as $status) {
        $closed = $fm->addOrFind(10, 'first', null, 'closed ' . $status);
        feedbackCheck($fm->setStatus($closed['id'], $status), 'close existing record: ' . $status);
        $next = $fm->addOrFind(20, 'second', null, 'CLOSED ' . $status);
        feedbackCheck($next['created'] && $next['id'] !== $closed['id'], 'closed history never merged: ' . $status);
    }
    $count = $fm->getPage()['total'];
    feedbackCheck($fm->addOrFind(10, 'first', null, str_repeat('ы', 1001)) === false && $fm->getPage()['total'] === $count, 'new API rejects >1000 without insertion');
    feedbackCheck($fm->addOrFind(10, 'first', null, str_repeat('ы', 1000))['created'], 'exact Unicode limit accepted');
    $long = $fm->add(10, 'legacy', null, str_repeat('я', 1001));
    feedbackCheck(is_int($long) && mb_strlen($db->query('SELECT text FROM feedback_backlog WHERE id=' . $long)->fetch_assoc()['text']) === 1000, 'legacy oversized text truncation retained');
    $db->begin_transaction();
    try { $fm->addOrFind(10, 'first', null, 'nested'); feedbackCheck(false, 'nested transaction rejected'); }
    catch (Core\UserError $e) { feedbackCheck($e->getMessage() === 'feedback_nested_transaction', 'nested transaction rejected before mutation'); }
    finally { $db->rollback(); }
    try { $fm->addOrFind(10, 'first', null, '__write_fault__'); feedbackCheck(false, 'write fault rolls back'); }
    catch (mysqli_sql_exception $e) { feedbackCheck($db->query("SELECT COUNT(*) n FROM feedback_backlog WHERE text='__write_fault__'")->fetch_assoc()['n'] === '0', 'write fault rolls back'); }
    feedbackCheck($fm->addOrFind(10, 'first', null, 'after fault')['created'], 'exception releases transaction and lock');

    $db->query("SELECT GET_LOCK('feedback_backlog_write',5)");
    foreach ([['101', 'Concurrent task'], ['102', " CONCURRENT\nTASK "]] as [$actor, $text]) {
        $ready = sys_get_temp_dir() . '/' . $schema . '_' . $actor;
        $readyFiles[] = $ready;
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $schema, $ready, $actor, $text], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('worker start failed');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 3;
    do { $ready = count(array_filter($readyFiles, 'is_file')) === 2; if (!$ready) usleep(10000); } while (!$ready && microtime(true) < $deadline);
    feedbackCheck($ready, 'both concurrent submitters reached barrier');
    $db->query("SELECT RELEASE_LOCK('feedback_backlog_write')");
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        feedbackCheck(proc_close($process) === 0 && $err === '', 'concurrent worker completed');
        $results[] = json_decode($out, true);
    }
    $workers = [];
    feedbackCheck($results[0]['id'] === $results[1]['id'] && (int)$results[0]['created'] + (int)$results[1]['created'] === 1, 'parallel submissions create exactly one task');
    feedbackCheck((int)$db->query("SELECT COUNT(*) n FROM feedback_backlog WHERE LOWER(text) LIKE '%concurrent%'")->fetch_assoc()['n'] === 1, 'parallel submissions persist one row');

    $closing = $fm->addOrFind(10, 'first', null, 'Closing race');
    $db->query("CREATE TRIGGER slow_close BEFORE UPDATE ON feedback_backlog FOR EACH ROW DO SLEEP(1)");
    $closeReady = sys_get_temp_dir() . '/' . $schema . '_close'; $readyFiles[] = $closeReady;
    $closePipes = [];
    $closeProcess = proc_open([PHP_BINARY, __FILE__, '--worker', $schema, $closeReady, '103', (string)$closing['id'], 'close'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $closePipes);
    fclose($closePipes[0]); $workers[] = [$closeProcess, $closePipes];
    $deadline = microtime(true) + 3;
    do {
        $held = $db->query("SELECT IS_USED_LOCK('feedback_backlog_write') owner")->fetch_assoc()['owner'] !== null;
        if (!$held) usleep(10000);
    } while (!$held && microtime(true) < $deadline);
    feedbackCheck($held, 'actual setStatus worker holds shared write lock');
    $ready = sys_get_temp_dir() . '/' . $schema . '_after_close'; $readyFiles[] = $ready;
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $schema, $ready, '104', 'Closing race'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]); $workers[] = [$process, $pipes];
    $closeOut = stream_get_contents($closePipes[1]); $closeErr = stream_get_contents($closePipes[2]);
    fclose($closePipes[1]); fclose($closePipes[2]);
    feedbackCheck(proc_close($closeProcess) === 0 && $closeErr === '' && json_decode($closeOut, true) === true, 'actual concurrent setStatus committed');
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    feedbackCheck(proc_close($process) === 0 && $err === '', 'waiting add completes after close commit'); $workers = [];
    $next = json_decode($out, true);
    feedbackCheck($next['created'] && $next['id'] !== $closing['id'], 'waiting add observes current closed status and preserves history');

} finally {
    foreach ($workers as [$process, $pipes]) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    foreach ($readyFiles as $path) if (is_file($path)) unlink($path);
    try { $db->rollback(); $db->query("SELECT RELEASE_LOCK('feedback_backlog_write')"); } finally {
        $db->close(); $admin->query("DROP DATABASE `$schema`"); $admin->close();
    }
}
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
