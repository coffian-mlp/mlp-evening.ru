<?php
/**
 * Юнит-тест UploadManager::resolveFromRequest (MLP-264, AR6-5) — безсетевые ветки.
 * Сетевые (uploadFromUrl) покрыты интеграционно/e2e; здесь — приоритет файла,
 * локальные пути, отбивка невалидных ссылок (фикс тихого сохранения, MLP-261).
 *
 * Запуск: php tests/test_upload_resolve.php
 */
require_once __DIR__ . '/../autoload.php';

use Core\UserError;
use Infra\UploadManager;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$um = new UploadManager();
unset($_FILES['avatar_file']);

ok($um->resolveFromRequest('avatar_file', '', '/upload/avatars/') === '', 'пусто → пустая строка (сброс не трогается)');
ok($um->resolveFromRequest('avatar_file', '/upload/avatars/av_1.png', '/upload/avatars/') === '/upload/avatars/av_1.png', 'локальный путь возвращается как есть (без сети)');

foreach (['javascript:alert(1)', 'не ссылка вовсе', '//evil.host/x.png'] as $bad) {
    $caught = null;
    try { $um->resolveFromRequest('avatar_file', $bad, '/upload/avatars/'); } catch (Throwable $e) { $caught = $e; }
    ok($caught instanceof UserError, "невалидная строка «{$bad}» → UserError (было: тихо сохранялась)");
}

$_FILES['avatar_file'] = ['error' => UPLOAD_ERR_NO_FILE];
ok($um->resolveFromRequest('avatar_file', '/upload/avatars/av_2.png', '/upload/avatars/') === '/upload/avatars/av_2.png', 'UPLOAD_ERR_NO_FILE = «файла нет», работает URL-ветка');
unset($_FILES['avatar_file']);

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
