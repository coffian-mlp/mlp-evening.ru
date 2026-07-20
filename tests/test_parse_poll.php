<?php
use LLM\LLMManager;
/**
 * Юнит-тест LLMManager::parsePoll() — MLP-240 (бот создаёт опрос).
 * Разбор ответа модели «вопрос + варианты построчно» в структуру опроса.
 *
 * LLMManager тянет ConfigManager→Database→config.php: на чистом клоне мягко SKIP.
 *
 * Запуск: php tests/test_parse_poll.php
 */

if (!file_exists(__DIR__ . '/../.env') && !file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: нет .env/config.php (нужен для загрузки LLMManager)\n";
    exit(0);
}

require_once __DIR__ . '/../src/LLM/LLMManager.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Простой опрос ==\n";
$p = LLMManager::parsePoll("Любимая пони?\nТвайлайт\nПинки\nРарити");
ok($p !== null, 'распарсился');
ok($p['question'] === 'Любимая пони?', 'вопрос — первая строка');
ok($p['options'] === ['Твайлайт', 'Пинки', 'Рарити'], '3 варианта');

echo "\n== Нумерация и маркеры чистятся ==\n";
$p = LLMManager::parsePoll("Кто круче?\n1. Твайлайт\n2) Пинки\n- Рарити\n• Эпплджек");
ok($p['options'] === ['Твайлайт', 'Пинки', 'Рарити', 'Эпплджек'], 'префиксы 1. / 2) / - / • убраны');

echo "\n== Пустые строки игнорируются ==\n";
$p = LLMManager::parsePoll("\nВопрос\n\nA\n\nB\n");
ok($p !== null && $p['question'] === 'Вопрос' && $p['options'] === ['A', 'B'], 'пустые строки отброшены');

echo "\n== Недостаточно данных → null ==\n";
ok(LLMManager::parsePoll("Только вопрос\nОдин вариант") === null, 'вопрос + 1 вариант → null');
ok(LLMManager::parsePoll("") === null, 'пусто → null');
ok(LLMManager::parsePoll(null) === null, 'null → null');

echo "\n== Ограничение до 10 вариантов ==\n";
$many = "Вопрос?\n" . implode("\n", array_map(fn($i) => "Вариант $i", range(1, 15)));
$p = LLMManager::parsePoll($many);
ok(count($p['options']) === 10, '15 вариантов обрезаны до 10');

echo "\n== parseUserPollSpec: явная заявка пользователя (основной режим) ==\n";
$s = LLMManager::parseUserPollSpec('/опрос Кто лучшая пони? Варианты: я, Лира, Твайлайт, Фоновая Пони 271, Чейнджлин 60189');
ok($s !== null, 'явная заявка распознана');
ok($s['question'] === 'Кто лучшая пони?', 'вопрос выделен');
ok($s['options'] === ['я', 'Лира', 'Твайлайт', 'Фоновая Пони 271', 'Чейнджлин 60189'], '5 вариантов, многословные сохранены');

ok(LLMManager::parseUserPollSpec('опрос Вопрос? Варианты: a, b')['question'] === 'Вопрос?', 'работает без слэша');
ok(LLMManager::parseUserPollSpec('/poll Q? Варианты: x, y') !== null, 'алиас /poll');
$dash = LLMManager::parseUserPollSpec('/опрос Тема - Варианты - раз, два, три');
ok($dash !== null && count($dash['options']) === 3, 'разделитель через тире');
$nl = LLMManager::parseUserPollSpec("/опрос Вопрос? Варианты:\nпервый\nвторой");
ok($nl !== null && $nl['options'] === ['первый', 'второй'], 'варианты с переносами строк');

echo "\n== parseUserPollSpec: нет явных вариантов → null (уходим в генерацию Лирой) ==\n";
ok(LLMManager::parseUserPollSpec('/опрос') === null, 'голая команда → null');
ok(LLMManager::parseUserPollSpec('/опрос придумай что-нибудь про погоду') === null, 'тема без «Варианты:» → null');
ok(LLMManager::parseUserPollSpec('/опрос Вопрос? Варианты: только один') === null, '1 вариант → null');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
