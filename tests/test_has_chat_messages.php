<?php
use LLM\LLMManager;
/**
 * Юнит-тест LLMManager::hasChatMessages (MLP-329): гейт «мёртвый чат» спонтанных реплик
 * смотрит на реальные реплики, а не на фоновые блоки (память, присутствие, закреп).
 * Запуск: php tests/test_has_chat_messages.php
 */
require_once __DIR__ . '/../autoload.php';
$fail = 0;
function ok($cond, $label) { global $fail; echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n"; if (!$cond) $fail++; }
$mem  = ['role' => 'user', 'content' => '[Заметки Лиры о завсегдатаях и мемах чата] Досье @Darbel: любит лего'];
$pres = ['role' => 'user', 'content' => '[Кто сейчас в чате]: CoFFian, Darbel. Это фон.'];
$pin  = ['role' => 'user', 'content' => '[Закреплено в чате, фоновый контекст]: правила'];
$msg  = ['role' => 'user', 'content' => '[22:51] CoFFian: ты знаешь, сколько сейчас времени?'];
$del  = ['role' => 'user', 'content' => '[22:52] (сообщение Darbel удалено)'];
$bot  = ['role' => 'assistant', 'content' => '[22:53] TotallyNotAPony: сверила копытца'];
ok(!LLMManager::hasChatMessages([]), 'пусто → нет');
ok(!LLMManager::hasChatMessages([$mem, $pres, $pin]), 'только фоновые блоки → нет (гейт срабатывает)');
ok(LLMManager::hasChatMessages([$mem, $pres, $msg]), 'блоки + реплика → есть');
ok(LLMManager::hasChatMessages([$del]), 'маркер удаления — тоже активность');
ok(LLMManager::hasChatMessages([$bot]), 'реплика бота считается');
ok(!LLMManager::hasChatMessages([['role' => 'user', 'content' => ['type' => 'image']]]), 'не-строковый content не ломает');
echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n"); exit($fail === 0 ? 0 : 1);
