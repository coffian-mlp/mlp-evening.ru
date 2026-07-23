<?php
use Domain\ChatManager;
use LLM\LLMManager;
/**
 * Интеграционный тест контекста бота (MLP-269 v2): удалённые видны боту
 * КАК ФАКТ («(сообщение X удалено)» / «(удалено N сообщений)» для серий),
 * но их содержимое не раскрывается, они не занимают лимит живых, серии
 * схлопываются. Пустышек «[HH:MM] Имя: » быть не должно.
 *
 * Запуск: docker compose exec php php tests/integration_bot_context.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

$cm = new ChatManager();
$marker = 'it_ctx_' . getmypid();
$ids = [];
$ids[] = (int)$cm->addMessage(1, 'ИтПони', "живое-1 $marker");
$ids[] = $d1 = (int)$cm->addMessage(1, 'ИтПони', "секрет-1 $marker");
$ids[] = $d2 = (int)$cm->addMessage(1, 'ИтПони', "секрет-2 $marker");
$ids[] = $d3 = (int)$cm->addMessage(999888, 'ЧужаяПони', "секрет-3 $marker");
$ids[] = (int)$cm->addMessage(1, 'ИтПони', "живое-2 $marker");
$ids[] = $d4 = (int)$cm->addMessage(1, 'ИтПони', "секрет-4 $marker");
foreach ([$d1, $d2, $d3, $d4] as $id) {
    check($cm->deleteMessage($id, 1, 'admin'), "удалено #$id");
}

try {
    $r = new ReflectionMethod(LLMManager::class, 'buildContext');
    $r->setAccessible(true);
    $ctx = $r->invoke(new LLMManager(), 10, null, null, false);

    $all = implode("\n", array_map(fn($c) => is_string($c['content']) ? $c['content'] : '', $ctx));
    check(str_contains($all, "живое-1 $marker") && str_contains($all, "живое-2 $marker"), 'живые сообщения в контексте');
    check(!str_contains($all, 'секрет-'), 'содержимое удалённых НЕ раскрывается');
    check((bool)preg_match('/\(удалено 3 сообщения: 2 — [^,]+, 1 — ЧужаяПони\)/u', $all), 'серия схлопнута с разбивкой по авторам (по убыванию): ' . $all);
    check((bool)preg_match('/\(сообщение [^)]+ удалено\)/u', $all), 'одиночное удаление — именной маркер (имя из users-джойна)');
    check(!str_contains($all, 'Сообщение удалено</em>'), 'HTML-плейсхолдер не просочился');

    foreach ($ctx as $c) {
        if (preg_match('/^\[\d\d:\d\d\] [^:]+: $/u', $c['content'])) {
            check(false, 'пустышек в контексте нет');
        }
    }
    check(true, 'пустышек в контексте нет');

    // Лимит живых: limit=4 (окно чтения 8 покрывает наши 6) — оба живых на месте,
    // 4 маркера удалённых лимит не съели. NB: запас чтения = limit*2 (cap 100) —
    // очень длинная серия удалённых может вытеснить старые живые (компромисс).
    $ctx2 = $r->invoke(new LLMManager(), 4, null, null, false);
    $all2 = implode("\n", array_map(fn($c) => $c['content'], $ctx2));
    check(str_contains($all2, "живое-1 $marker") && str_contains($all2, "живое-2 $marker"), 'маркеры удалённых не занимают лимит живых');
} finally {
    // Уборка: тестовые сообщения — физически (своя таблица под тестом, Docker-контур).
    $in = implode(',', array_map('intval', $ids));
    $conn->query("DELETE FROM chat_messages WHERE id IN ($in)");
}

it_done();
