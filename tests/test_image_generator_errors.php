<?php
use LLM\ImageGenerator;
use LLM\LyraArtist;
/**
 * Юнит-тест (MLP-336): распознавание отказа фильтра безопасности в теле HTTP 200 и смягчение сцены.
 * БД не нужна. Запуск: php tests/test_image_generator_errors.php
 */
require_once __DIR__ . '/../autoload.php';
$fail = 0;
function ok($cond, $label) { global $fail; echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n"; if (!$cond) $fail++; }

echo "== extractError ==\n";
$azure = str_repeat("\n         \n", 12) . '{"error":{"message":"Output content violated imagegen safety policies.","code":400,"metadata":{"provider_name":"Azure"}}}';
ok(ImageGenerator::extractError($azure) === 'Output content violated imagegen safety policies.', 'error-JSON после keep-alive пробелов распознан');
ok(ImageGenerator::extractError('{"data":[{"b64_json":"AAAA"}]}') === null, 'нормальный ответ → null');
ok(ImageGenerator::extractError('') === null && ImageGenerator::extractError('   ') === null, 'пусто → null');
ok(ImageGenerator::extractError('<html>502</html>') === null, 'не-JSON → null (обработает HTTP-ветка)');

echo "\n== isSafetyMessage ==\n";
ok(ImageGenerator::isSafetyMessage('Output content violated imagegen safety policies.'), 'safety policies');
ok(ImageGenerator::isSafetyMessage('Response content blocked by content filter'), 'content blocked');
ok(!ImageGenerator::isSafetyMessage('Rate limit exceeded'), 'rate limit — не фильтр');
ok(!ImageGenerator::isSafetyMessage(null), 'null → false');

echo "\n== softenScene ==\n";
$scene = 'Darbel (unicorn with deep purple coat) explains his hour-and-a-half chicken heart preparation while CoFFian shoots a rifle at the screen and drinks beer.';
$soft = LyraArtist::softenScene($scene);
ok(!preg_match('/heart|rifle|shoots|beer/i', $soft), 'опасные слова заменены: ' . mb_substr($soft, 0, 120));
ok(str_contains($soft, 'deep purple coat'), 'внешность сохранена');
ok(str_contains($soft, 'Wholesome, cute and calm'), 'добавлено безобидное указание');
ok(mb_strlen(LyraArtist::softenScene(str_repeat('a long scene ', 60))) < 600, 'длина ограничена');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n"); exit($fail === 0 ? 0 : 1);
