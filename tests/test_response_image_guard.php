<?php
use LLM\ResponseSanitizer;
/**
 * Юнит-тест страховки от выдуманных картинок (MLP-351): ResponseSanitizer::hasImage.
 * Реплика модели с картинкой отбрасывается в askWithFallback целиком — настоящие рисунки
 * код приклеивает к подписи уже после ответа. Шаблоны — те же, что отрисовывает чат
 * (markdown-картинка и голая ссылка на картинку). Pure, без БД.
 *
 * Запуск: php tests/test_response_image_guard.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Выдумка ловится ==\n";
$fake = "@Wellerman, лови! Нарисовала по памяти — точнее, по щекотке в мыслях) Клод, если увидишь и захочешь поправить мантию — знаешь, где мои настройки)\n![рисунок](/upload/lyra/lyra_9d2f1c7e40aa3_8c11b2f7.jpg)";
ok(ResponseSanitizer::hasImage($fake), 'прецедент 26.09: «Нарисовала по памяти» с markdown-картинкой');
ok(ResponseSanitizer::hasImage('Вот! ![](https://example.com/pony.webp)'), 'markdown-картинка с внешним адресом');
ok(ResponseSanitizer::hasImage('Держи: https://mlp-evening.ru/upload/lyra/lyra_x.jpg'), 'голая ссылка на .jpg — чат покажет картинкой');
ok(ResponseSanitizer::hasImage('смотри HTTPS://CDN.EXAMPLE/ART.PNG'), 'регистр расширения и схемы не важен');
ok(ResponseSanitizer::hasImage('https://example.com/a.gif?size=big'), 'ссылка с параметрами после расширения');
ok(ResponseSanitizer::hasImage('https://example.com/a.jpeg'), '.jpeg');

echo "\n== Обычный текст не трогается ==\n";
ok(!ResponseSanitizer::hasImage('Ура! [шутка] (смех) — вот это вечер!'), 'восклицание и скобки — не картинка');
ok(!ResponseSanitizer::hasImage('Напиши /нарисуй пони в шляпе — и я возьмусь за кисть!'), 'подсказка команды');
ok(!ResponseSanitizer::hasImage('Серия тут: https://www.youtube.com/watch?v=abcdefghijk'), 'ссылка на видео');
ok(!ResponseSanitizer::hasImage('Гайд: https://example.com/gif-guide'), '«gif» в пути без расширения');
ok(!ResponseSanitizer::hasImage('Держи стикер :pinkie_party:'), 'стикер :код: — не картинка из текста');
ok(!ResponseSanitizer::hasImage('лежит где-то в /upload/lyra/old.jpg'), 'голый относительный путь чат не рисует');
ok(!ResponseSanitizer::hasImage(''), 'пусто');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
