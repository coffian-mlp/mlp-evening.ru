<?php
require __DIR__ . '/../autoload.php';

use Domain\SiteAppearance;

$fail = 0;
foreach (['', ' /upload/bg.webp ', '/upload/фон.png', '/upload/img.png?v=2&size=large', 'https://example.org/bg.webp', 'http://example.org/logo.png'] as $value) {
    $ok = SiteAppearance::normalizeImageUrl($value) === trim($value);
    echo ($ok ? '[OK]' : '[FAIL]') . " accepted image reference\n";
    if (!$ok) $fail++;
}
foreach (['//example.org/a.png', 'javascript:alert(1)', 'data:image/svg+xml,test', 'ftp://example.org/a.png', 'relative.png', '/x";color:red', '/x</style>', '/x\\evil', '/x y', "/x\ncss", 'https://user:pass@example.org/a', [], str_repeat('a', 2049)] as $value) {
    try { SiteAppearance::normalizeImageUrl($value); $fail++; echo "[FAIL] unsafe reference accepted\n"; }
    catch (InvalidArgumentException $e) { echo "[OK] invalid image reference rejected\n"; }
}
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
