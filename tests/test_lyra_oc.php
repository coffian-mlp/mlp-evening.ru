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
ok(LyraOc::parse($long) === $long, 'длинное не обрезается (решение владельца 19.09)');
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

echo "\n== parseSplit / joinPersona (MLP-340) ==\n";
$sp = LyraOc::parseSplit("внешность: единорог, серый плащ с эмблемой полумесяца, шляпа-федора, кьютимарка — скрыта\nперсонаж: лунарный агент принцессы Луны, предпочитает дальний бой и скрытность, плох в социалке");
ok($sp['look'] === 'единорог, серый плащ с эмблемой полумесяца, шляпа-федора, кьютимарка — скрыта', 'внешность разобрана');
ok($sp['persona'] === 'лунарный агент принцессы Луны, предпочитает дальний бой и скрытность, плох в социалке', 'персонаж разобран');
ok(LyraOc::parseSplit('**Персонаж:** «скрытный агент»')['persona'] === 'скрытный агент' && LyraOc::parseSplit('**Персонаж:** «скрытный агент»')['look'] === null, 'только персонаж, markdown снят');
ok(LyraOc::parseSplit('внешность: пони')['look'] === null, 'слишком короткая внешность отброшена');
ok(LyraOc::parseSplit(null) === ['look' => null, 'persona' => null], 'null → пусто');
ok(LyraOc::joinPersona(null, 'лунарный агент') === 'лунарный агент', 'первый лор');
ok(LyraOc::joinPersona('лунарный агент', 'плох в социалке') === 'лунарный агент; плох в социалке', 'дописывается через ;');
ok(LyraOc::joinPersona('лунарный агент; плох в социалке', 'Плох в социалке') === 'лунарный агент; плох в социалке', 'дубль не дописывается');
ok(mb_strlen(LyraOc::joinPersona(str_repeat('факт ', 60), 'ещё один')) > 300, 'лор не обрезается');
ok(LyraOc::currentPersona([['text' => 'внешность: пегас'], ['text' => 'ОС: агент Луны']]) === 'агент Луны', 'текущий лор без префикса');

echo "\n== personaIsClean (MLP-341) ==\n";
ok(LyraOc::personaIsClean('лунарный агент на службе принцессы Луны, работает из тени'), 'чистый русский лор');
ok(!LyraOc::personaIsClean('единорог-бухгалклав на сидрoonном складе «Бочка и Якорь»'), 'смешанное слово с латиницей → мусор');
ok(!LyraOc::personaIsClean('Пекарша- sweet tooth Понивилля'), 'английские слова внутри → мусор');
ok(!LyraOc::personaIsClean('коротко') && !LyraOc::personaIsClean(null), 'коротко/null → не годится');

echo "\n== hasAppearance ==\n";
ok(LyraOc::hasAppearance([['text' => 'любит чай'], ['text' => 'внешность: пегас']]), 'есть внешность');
ok(!LyraOc::hasAppearance([['text' => 'любит чай']]), 'нет внешности');
ok(LyraOc::isAppearance('Внешность — единорог') && !LyraOc::isAppearance('внешностью не вышел'), 'детект по префиксу с разделителем');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n"); exit($fail === 0 ? 0 : 1);
