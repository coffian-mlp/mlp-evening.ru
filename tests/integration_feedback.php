<?php
use Domain\FeedbackManager;
use LLM\LLMManager;
/**
 * Интеграционный тест беклога /todo (MLP-270): FeedbackManager CRUD +
 * полный путь команды через LLMManager::processTrigger (без LLM).
 * Контракт: dev_knowledge/contracts/feedback.contract.md.
 *
 * Запуск: docker compose exec php php tests/integration_feedback.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$fm = new FeedbackManager();
$marker = 'it_fb_' . getmypid();
$createdIds = [];
$msgIds = [];

try {
    echo "== FeedbackManager CRUD ==\n";
    $id = $fm->add(1, 'ИтПони', null, "идея: $marker");
    $createdIds[] = $id;
    check(is_int($id) && $id > 0, 'add возвращает id');
    check($fm->add(1, 'ИтПони', null, '   ') === false, 'пустой текст → false');

    $long = $fm->add(1, 'ИтПони', null, str_repeat('ы', 3000) . $marker);
    $createdIds[] = $long;
    $page = $fm->getPage(5, 0, 'new');
    check($page['total'] >= 2, 'getPage(new) видит записи');
    $item = null;
    foreach ($page['items'] as $it) if ((int)$it['id'] === (int)$long) $item = $it;
    check($item && mb_strlen($item['text']) <= FeedbackManager::MAX_TEXT, 'длинный текст обрезан до лимита');

    check($fm->setStatus((int)$id, 'done'), 'setStatus → done');
    check(!$fm->setStatus((int)$id, 'взорвать'), 'кривой статус отвергнут');
    check($fm->setStatus(99999999, 'done') === false, 'несуществующий id → false');

    echo "== Полный путь команды (processTrigger, без LLM) ==\n";
    // Включаем бота в тестовой БД: гейт isEnabled требует ai_enabled + бот-юзер +
    // хотя бы один провайдер (ключ-пустышка; todo-путь LLM не вызывает).
    $cfg = \Infra\ConfigManager::getInstance();
    $prevOpts = [];
    foreach (['ai_enabled' => '1', 'ai_bot_user_id' => '1', 'ai_routerai_key' => 'it-dummy'] as $k => $v) {
        $prevOpts[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }
    $llm = new LLMManager();

    $before = $fm->getPage(1, 0)['total'];
    $llm->processTrigger('dynamic_command', [
        'message' => "/todo починить погоду в Клаудсдейле $marker",
        'command' => ['handler_type' => 'todo', 'command_prefix' => '/todo'],
        'user_id' => 1,
        'username' => 'ИтПони',
        'message_id' => null,
    ]);
    $page = $fm->getPage(3, 0);
    check($page['total'] === $before + 1, 'запись создана через команду');
    check(str_contains($page['items'][0]['text'], "Клаудсдейле $marker"), 'префикс /todo срезан, текст сохранён');
    $createdIds[] = (int)$page['items'][0]['id'];

    // Подтверждение бота в чате
    $res = $conn->query("SELECT id, message FROM chat_messages ORDER BY id DESC LIMIT 2");
    $confirm = '';
    while ($row = $res->fetch_assoc()) { $msgIds[] = (int)$row['id']; $confirm .= $row['message'] . "\n"; }
    check(str_contains($confirm, '@ИтПони') && preg_match('/№\d+/u', $confirm), 'бот подтвердил записью с номером');

    // Пустой /todo → подсказка, записи нет
    $before = $fm->getPage(1, 0)['total'];
    $llm->processTrigger('dynamic_command', [
        'message' => '/todo',
        'command' => ['handler_type' => 'todo', 'command_prefix' => '/todo'],
        'user_id' => 1, 'username' => 'ИтПони', 'message_id' => null,
    ]);
    check($fm->getPage(1, 0)['total'] === $before, 'пустой /todo не создаёт запись');
    $res = $conn->query("SELECT id, message FROM chat_messages ORDER BY id DESC LIMIT 1");
    $row = $res->fetch_assoc(); $msgIds[] = (int)$row['id'];
    check(str_contains($row['message'], 'что записать'), 'на пустой /todo — подсказка');
} finally {
    if (isset($prevOpts)) {
        foreach ($prevOpts as $k => $v) {
            if ($v === null) $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
            else \Infra\ConfigManager::getInstance()->setOption($k, $v);
        }
    }
    foreach ($createdIds as $cid) if ($cid) $conn->query("DELETE FROM feedback_backlog WHERE id = " . (int)$cid);
    $conn->query("DELETE FROM feedback_backlog WHERE text LIKE '%$marker%'");
    foreach (array_unique($msgIds) as $mid) if ($mid) $conn->query("DELETE FROM chat_messages WHERE id = " . (int)$mid);
}

it_done();
