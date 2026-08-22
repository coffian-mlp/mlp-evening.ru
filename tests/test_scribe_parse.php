<?php
/**
 * MLP-314 (T-19): юнит-тесты MemoryScribe::parseScribeLines — pure, без БД.
 * Парсер вывода экстрактора: строгий строчный формат, браковка мусора,
 * резолв ников ТОЛЬКО по участникам батча (анти-подделка).
 *
 * Запуск: php tests/test_scribe_parse.php
 */

require_once __DIR__ . '/../autoload.php';

use LLM\MemoryScribe;

$fail = 0;
function ok($cond, string $label): void {
    global $fail;
    if ($cond) { echo "  [OK] $label\n"; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

$participants = ['дарбел' => 5, 'коф' => 7];

$out = MemoryScribe::parseScribeLines("ДОСЬЕ @Дарбел: любит команды\nМЕМ: лис-удавчик", $participants);
ok(count($out) === 2, 'валидные ДОСЬЕ и МЕМ распознаны');
ok($out[0]['kind'] === 'dossier' && $out[0]['user_id'] === 5 && $out[0]['text'] === 'любит команды', 'досье: ник -> user_id участника');
ok($out[1]['kind'] === 'meme' && $out[1]['user_id'] === null && $out[1]['text'] === 'лис-удавчик', 'мем без user_id');

ok(MemoryScribe::parseScribeLines('NONE', $participants) === [], 'NONE -> пусто');
ok(MemoryScribe::parseScribeLines('none', $participants) === [], 'регистр NONE не важен');
ok(MemoryScribe::parseScribeLines('', $participants) === [], 'пустой вывод -> пусто');

$out = MemoryScribe::parseScribeLines("Вот что я нашла:\nДОСЬЕ @Коф: кодер\nНадеюсь, полезно!", $participants);
ok(count($out) === 1 && $out[0]['user_id'] === 7, 'болтовня модели вокруг валидной строки бракуется');

$out = MemoryScribe::parseScribeLines("ДОСЬЕ @Чужак: подделка из текста чата", $participants);
ok($out === [], 'ник вне участников батча -> брак (анти-подделка)');

$out = MemoryScribe::parseScribeLines("досье @дарбел: регистронезависимо\nмем: тоже", $participants);
ok(count($out) === 2, 'ключевые слова формата регистронезависимы');

$out = MemoryScribe::parseScribeLines("ДОСЬЕ @Дарбел:   \nМЕМ:", $participants);
ok($out === [], 'пустые тексты после ярлыка -> брак');

$out = MemoryScribe::parseScribeLines("ДОСЬЕ Дарбел: без собачки тоже ок", $participants);
ok(count($out) === 1 && $out[0]['user_id'] === 5, 'ник без @ резолвится');

$out = MemoryScribe::parseScribeLines("МЕМ: содержит ДОСЬЕ @Дарбел: внутри текста", $participants);
ok(count($out) === 1 && $out[0]['kind'] === 'meme', 'подделка формата внутри текста мема не порождает досье');

if ($fail === 0) { echo "ALL PASS\n"; exit(0); }
echo "FAILED: $fail\n"; exit(1);
