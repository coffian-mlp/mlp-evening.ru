<?php
/**
 * Юнит-тест MLP-297: живое извинение художницы за провал генерации.
 * Pure-части: LyraArtist::excuseInstruction (инструкция для LLM с причиной
 * от рисовальной модели) и ImageGenerator::failureReason (выжимка причины
 * из ответа провайдера: error.message → curl → голый HTTP-код).
 *
 * БД не нужна. Запуск: php tests/test_lyra_excuse.php
 */

require_once __DIR__ . '/../autoload.php';

use LLM\ImageGenerator;
use LLM\LyraArtist;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== excuseInstruction: причина вплетена ==\n";
$i = LyraArtist::excuseInstruction('пони на облаке', 'ИтПони', 'Gemini returned no image data (finish_reason: STOP)');
ok(str_contains($i, '@ИтПони'), 'адресат в инструкции');
ok(str_contains($i, 'пони на облаке'), 'сюжет в инструкции');
ok(str_contains($i, 'Gemini returned no image data'), 'ответ рисовальной машины передан LLM');
ok(str_contains($i, 'НЕ ПОЛУЧИЛСЯ'), 'явно сказано, что рисунок не вышел');
ok(str_contains($i, 'НЕ вставляй ссылки'), 'запрет ссылок/картинок');

echo "\n== excuseInstruction: без причины ==\n";
$i = LyraArtist::excuseInstruction('гроза', 'ИтПони', null);
ok(!str_contains($i, 'Рисовальная машина ответила'), 'нет причины — нет пустой цитаты');
$i = LyraArtist::excuseInstruction('гроза', 'ИтПони', '   ');
ok(!str_contains($i, 'Рисовальная машина ответила'), 'пробельная причина отброшена');

echo "\n== excuseInstruction: длинное режется ==\n";
$i = LyraArtist::excuseInstruction(str_repeat('о', 500), 'ИтПони', str_repeat('e', 900));
ok(mb_strlen($i) < 900, 'сюжет и причина ограничены (200/300)');

echo "\n== failureReason: error.message из JSON ==\n";
$body = '{"error":{"message":"Gemini returned no image data (finish_reason: STOP)","code":400}}';
ok(ImageGenerator::failureReason($body, '', 400) === 'Gemini returned no image data (finish_reason: STOP)', 'сообщение провайдера извлечено');

echo "\n== failureReason: fallback-цепочка ==\n";
ok(str_contains(ImageGenerator::failureReason('не json', 'Connection timed out', 0), 'Connection timed out'), 'curl-ошибка, когда JSON нет');
ok(ImageGenerator::failureReason('', '', 502) === 'провайдер ответил ошибкой HTTP 502', 'голый HTTP-код — последний рубеж');
ok(mb_strlen(ImageGenerator::failureReason('{"error":{"message":"' . str_repeat('x', 600) . '"}}', '', 400)) <= 300, 'длинное сообщение обрезано до 300');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
