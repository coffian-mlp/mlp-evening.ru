<?php
/**
 * MLP-315: цитирование сообщения-опроса — в карточке цитаты вместо литерального
 * тега [[poll:N]] отдаётся компактная заглушка «📊 Опрос: <вопрос>».
 * Фикстуры: polls через владельца PollManager, chat_messages — таблица тестируемого
 * ChatManager (прямой SQL допустим, уборка в finally).
 *
 * Запуск: docker compose exec php php tests/integration_quote_poll.php
 */

require_once __DIR__ . '/integration_helpers.php';

use Domain\ChatManager;
use Domain\PollManager;

$conn = it_require_db();
$marker = 'itqp_' . getmypid();

$msgIds = [];
$pollId = null;

try {
    $pm = new PollManager();
    $chat = new ChatManager();

    $pollId = $pm->create(1, "Вопрос для цитаты {$marker}?", ['Да', 'Нет']);
    check($pollId > 0, 'опрос создан');

    $ins = function (string $text, ?string $quotedJson = null) use ($conn, $marker, &$msgIds): int {
        $stmt = $conn->prepare("INSERT INTO chat_messages (user_id, username, message, created_at, quoted_msg_ids) VALUES (1, ?, ?, UTC_TIMESTAMP(), ?)");
        $uname = $marker;
        $stmt->bind_param('sss', $uname, $text, $quotedJson);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $msgIds[] = $id;
        return $id;
    };

    $pollMsgId = $ins("[[poll:{$pollId}]]");
    $ins("гляньте опрос выше {$marker}", json_encode([$pollMsgId]));

    $messages = $chat->getMessages(10);
    $quoting = array_values(array_filter($messages, fn($m) => str_contains($m['message'] ?? '', "выше {$marker}")));
    check(count($quoting) === 1, 'цитирующее сообщение найдено');
    $quote = $quoting[0]['quotes'][0] ?? null;
    check(is_array($quote), 'цитата приложена');
    check(str_contains($quote['message'], '📊 Опрос: Вопрос для цитаты'), 'цитата опроса — заглушка с вопросом, не тег');
    check(!str_contains($quote['message'], '[[poll:'), 'литеральный тег в цитате отсутствует');

    // Обычная цитата не задета (регресс)
    $plainId = $ins("обычный текст {$marker}");
    $ins("ответ {$marker}", json_encode([$plainId]));
    $messages = $chat->getMessages(10);
    $quoting = array_values(array_filter($messages, fn($m) => str_contains($m['message'] ?? '', "ответ {$marker}")));
    check(str_contains($quoting[0]['quotes'][0]['message'] ?? '', "обычный текст {$marker}"), 'обычная цитата рендерится как раньше');

    // Опрос удалён из БД -> заглушка без вопроса, без ошибок
    $conn->query("DELETE FROM poll_votes WHERE poll_id = " . (int)$pollId);
    $conn->query("DELETE FROM poll_options WHERE poll_id = " . (int)$pollId);
    $conn->query("DELETE FROM polls WHERE id = " . (int)$pollId);
    $gone = $pollId; $pollId = null;
    $messages = $chat->getMessages(10);
    $quoting = array_values(array_filter($messages, fn($m) => str_contains($m['message'] ?? '', "выше {$marker}")));
    $qmsg = $quoting[0]['quotes'][0]['message'] ?? '';
    check(str_contains($qmsg, '📊 Опрос') && !str_contains($qmsg, 'Вопрос для цитаты'), 'удалённый опрос -> заглушка без вопроса');

} finally {
    if ($msgIds) {
        $conn->query("DELETE FROM chat_messages WHERE id IN (" . implode(',', array_map('intval', $msgIds)) . ")");
    }
    if ($pollId) {
        $conn->query("DELETE FROM poll_votes WHERE poll_id = " . (int)$pollId);
        $conn->query("DELETE FROM poll_options WHERE poll_id = " . (int)$pollId);
        $conn->query("DELETE FROM polls WHERE id = " . (int)$pollId);
    }
}

it_done();
