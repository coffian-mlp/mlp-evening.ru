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
ok(str_contains($t, "Ник: Назар\nЦвет ника: не задан"), 'ник и отсутствие цвета');
ok(str_contains($t, 'любит стратегии; Стиль: молчун %)'), 'факты очищены от markdown');
ok(!str_contains($t, 'старое'), 'прежняя внешность в задание не попадает');
ok(str_contains(LyraOc::taskText('CoFFian', 'bright pink', []), 'Цвет ника: bright pink') && str_contains(LyraOc::taskText('CoFFian', 'bright pink', []), 'Факты: нет'), 'цвет передан, фактов нет');

echo "\n== hasAppearance ==\n";
ok(LyraOc::hasAppearance([['text' => 'любит чай'], ['text' => 'внешность: пегас']]), 'есть внешность');
ok(!LyraOc::hasAppearance([['text' => 'любит чай']]), 'нет внешности');
ok(LyraOc::isAppearance('Внешность — единорог') && !LyraOc::isAppearance('внешностью не вышел'), 'детект по префиксу с разделителем');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n"); exit($fail === 0 ? 0 : 1);
