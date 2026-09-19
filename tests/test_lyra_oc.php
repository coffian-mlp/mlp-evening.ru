<?php
use LLM\LyraOc;
/**
 * Юнит-тест LyraOc (MLP-335): разбор ответа генератора обликов, текст задания, детект внешности.
 * БД не нужна. Запуск: php tests/test_lyra_oc.php
 */
require_once __DIR__ . '/../autoload.php';
$fail = 0;
function ok($cond, $label) { global $fail; echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n"; if (!$cond) $fail++; }

echo "== parse ==\n";
ok(LyraOc::parse('внешность: земнопони, пшеничная шёрстка, лохматая золотая грива, сонные глаза, кьютимарка — колосок') === 'внешность: земнопони, пшеничная шёрстка, лохматая золотая грива, сонные глаза, кьютимарка — колосок', 'чистая строка — как есть');
ok(LyraOc::parse("Вот облик:\n**Внешность:** «единорог, мятная шёрстка, кьютимарка — лира.»") === 'внешность: единорог, мятная шёрстка, кьютимарка — лира', 'markdown/кавычки/регистр/точка сняты, префикс нормализован');
$long = 'внешность: ' . implode(', ', array_fill(0, 20, 'очень длинная деталь'));
$p = LyraOc::parse($long);
ok($p !== null && mb_strlen($p) <= mb_strlen(LyraOc::PREFIX) + 1 + LyraOc::MAX_LEN && str_ends_with($p, 'деталь'), 'длинное режется по запятой: ' . mb_strlen((string)$p));
ok(LyraOc::parse('внешность: пони') === null, 'слишком коротко → null');
ok(LyraOc::parse('Это пегас с синей гривой') === null, 'без префикса → null');
ok(LyraOc::parse(null) === null && LyraOc::parse('') === null, 'null/пусто → null');

echo "\n== taskText ==\n";
$t = LyraOc::taskText('Назар', '', ['любит стратегии', 'Внешность: старое', '**Стиль:** молчун %)']);
ok(str_contains($t, "Ник: Назар\nЦвет ника (для гривы или акцента, не шёрстки): не задан"), 'ник и отсутствие цвета');
ok(str_contains($t, 'любит стратегии; Стиль: молчун %)'), 'факты очищены от markdown');
ok(!str_contains($t, 'старое'), 'прежняя внешность в задание не попадает');
ok(str_contains(LyraOc::taskText('CoFFian', 'bright pink', []), 'не шёрстки): bright pink') && str_contains(LyraOc::taskText('CoFFian', 'bright pink', []), 'Факты: нет'), 'цвет передан, фактов нет');

echo "\n== extractImageUrl / stripImages (MLP-337) ==\n";
ok(LyraOc::extractImageUrl('![изображение.png](/upload/chat/chat_6aaed07c4f062_359f75bd.png)') === '/upload/chat/chat_6aaed07c4f062_359f75bd.png', 'markdown-вложение чата');
ok(LyraOc::extractImageUrl('вот она https://example.com/oc.PNG?x=1 смотри') === 'https://example.com/oc.PNG?x=1', 'прямая ссылка на картинку');
ok(LyraOc::extractImageUrl('серая кобылка в очках') === null, 'текст без картинки → null');
ok(LyraOc::extractImageUrl('https://example.com/page.html') === null, 'ссылка не на картинку → null');
ok(LyraOc::stripImages('это моя ОС, без очков ![oc.png](/upload/chat/x.png)') === 'это моя ОС, без очков', 'картинка убрана, пожелание осталось');
ok(LyraOc::stripImages('![oc.png](/upload/chat/x.png)') === '', 'только картинка → пусто');

echo "\n== parseAugment / currentLook (MLP-339) ==\n";
ok(LyraOc::parseAugment('дополни красный шарф и очки') === 'красный шарф и очки', '«дополни …»');
ok(LyraOc::parseAugment('Добавь: шляпу') === 'шляпу', '«Добавь:» регистронезависимо, двоеточие снято');
ok(LyraOc::parseAugment('+ монокль') === 'монокль', '«+ …»');
ok(LyraOc::parseAugment('дополни') === '', '«дополни» без текста → пустая строка (а не null)');
ok(LyraOc::parseAugment('серая кобылка в очках') === null, 'обычное описание → null');
ok(LyraOc::parseAugment('дополнительно грустная') === null, '«дополнительно» — не команда дополнения');
ok(LyraOc::currentLook([['text' => 'любит чай'], ['text' => 'Внешность: пегас, серый']]) === 'пегас, серый', 'текущий облик без префикса');
ok(LyraOc::currentLook([['text' => 'любит чай']]) === null, 'нет облика → null');

echo "\n== hasAppearance ==\n";
ok(LyraOc::hasAppearance([['text' => 'любит чай'], ['text' => 'внешность: пегас']]), 'есть внешность');
ok(!LyraOc::hasAppearance([['text' => 'любит чай']]), 'нет внешности');
ok(LyraOc::isAppearance('Внешность — единорог') && !LyraOc::isAppearance('внешностью не вышел'), 'детект по префиксу с разделителем');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n"); exit($fail === 0 ? 0 : 1);
