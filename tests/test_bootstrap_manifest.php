<?php
require_once dirname(__DIR__) . '/bootstrap.php';

$manifest = bootstrap_manifest();
$files = migrate_all_files(dirname(__DIR__) . '/migrations');
$fail = 0;
function manifestCheck(bool $ok, string $label): void {
    global $fail;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $fail++;
}
manifestCheck(count($manifest['migrations']) === 43, 'snapshot has a fixed historical migration list');
manifestCheck(array_diff($manifest['migrations'], $files) === [], 'every pinned historical migration exists');
manifestCheck(migration_pending(array_merge($manifest['migrations'], ['2099_01_01_future.sql']), $manifest['migrations']) === ['2099_01_01_future.sql'], 'future files are never silently baselined');
bootstrap_verify_files($manifest);
manifestCheck(true, 'snapshot and seed match the pinned manifest');
$changed = $manifest;
$changed['files']['database.sample.sql'] = str_repeat('0', 64);
try {
    bootstrap_verify_files($changed);
    manifestCheck(false, 'changed snapshot rejected');
} catch (RuntimeException $error) {
    manifestCheck(str_contains($error->getMessage(), 'database.sample.sql'), 'changed snapshot rejected');
}
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
