<?php
use Domain\ChatManager;
use LLM\LLMManager;
/**
 * Интеграционный тест контекста бота (MLP-269): удалённые сообщения
 * НЕ попадают в buildContext (раньше шли пустышками — у удалённых
 * getMessages не заполняет raw_message).
 *
 * Запуск: docker compose exec php php tests/integration_bot_context.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

$cm = new ChatManager();
$marker = 'it_ctx_' . getmypid();
$aliveId = $cm->addMessage(1, 'ИтПони', "живое $marker");
$deadId  = $cm->addMessage(1, 'ИтПони', "мертвое $marker");
check(is_int($aliveId) || $aliveId === true, 'живое сообщение создано');
check($cm->deleteMessage((int)$deadId, 1, 'admin'), 'второе удалено (soft)');

try {
    $r = new ReflectionMethod(LLMManager::class, 'buildContext');
    $r->setAccessible(true);
    $ctx = $r->invoke(new LLMManager(), 10, null, null, false);

    $all = implode("\n", array_map(fn($c) => is_string($c['content']) ? $c['content'] : '', $ctx));
    check(str_contains($all, "живое $marker"), 'живое сообщение в контексте');
    check(!str_contains($all, "мертвое $marker"), 'удалённое сообщение НЕ в контексте');
    check(!str_contains($all, 'Сообщение удалено'), 'плейсхолдер удаления не просочился');

    foreach ($ctx as $c) {
        if (preg_match('/^\[\d\d:\d\d\] [^:]+: $/u', $c['content'])) {
            check(false, 'пустышек в контексте нет');
        }
    }
    check(true, 'пустышек в контексте нет');
} finally {
    // Уборка: тестовые сообщения — физически (своя таблица под тестом, Docker-контур).
    $stmt2 = $conn->prepare("DELETE FROM chat_messages WHERE id IN (?, ?)");
    $a = (int)$aliveId; $d = (int)$deadId;
    $stmt2->bind_param('ii', $a, $d);
    $stmt2->execute();
}

it_done();
