<?php
/**
 * MLP-314 (T-20): интеграционные тесты автописи памяти (фаза 2).
 * Планировщик BotWorker::memoryScribeSchedule (через Reflection — образец
 * integration_proactive_queue) и MemoryScribe::runScribe с фейковым провайдером.
 * Изоляция: job'ы только свои (id > baseId), опции восстанавливаются в finally.
 *
 * Запуск: docker compose exec php php tests/integration_memory_scribe.php
 */

require_once __DIR__ . '/integration_helpers.php';

use LLM\BotWorker;
use LLM\JobQueue;
use LLM\MemoryScribe;

$conn = it_require_db();
$marker = 'itms_' . getmypid();

$optBackup = [];
$cleanupUserIds = [];
$cleanupMsgIds = [];
$baseJobId = (int)($conn->query("SELECT COALESCE(MAX(id), 0) m FROM llm_jobs")->fetch_assoc()['m']);

$myScribeJobs = fn() => (int)$conn->query("SELECT COUNT(*) c FROM llm_jobs WHERE type='memory_scribe' AND id > $baseJobId")->fetch_assoc()['c'];

try {
    $cfg = \Infra\ConfigManager::getInstance();

    // Пользователь и бот
    $mk = function (string $login, string $nick) use ($conn, &$cleanupUserIds): int {
        $stmt = $conn->prepare("INSERT INTO users (login, nickname, email, password_hash, role) VALUES (?, ?, ?, 'x', 'user')");
        $email = $login . '@test.local';
        $stmt->bind_param('sss', $login, $nick, $email);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $cleanupUserIds[] = $id;
        return $id;
    };
    $humanId = $mk("{$marker}_human", "{$marker}_Пони");
    $botId = $mk("{$marker}_bot", "{$marker}_Лира");

    $ins = function (?int $userId, string $text) use ($conn, $marker, &$cleanupMsgIds): int {
        $stmt = $conn->prepare("INSERT INTO chat_messages (user_id, username, message, created_at) VALUES (?, ?, ?, NOW())");
        $uname = $marker . '_u';
        $stmt->bind_param('iss', $userId, $uname, $text);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $cleanupMsgIds[] = $id;
        return $id;
    };

    foreach (['ai_enabled' => '1', 'ai_bot_user_id' => (string)$botId, 'ai_routerai_key' => 'it-dummy',
              'ai_memory_enabled' => '1', 'ai_memory_auto' => '1', 'ai_memory_interval' => '21600',
              'bot_memory_last_run' => '0', 'bot_memory_backlog' => '0',
              'bot_memory_last_id' => null /* снимем и проверим инициализацию */] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        if ($v === null) {
            $conn->query("DELETE FROM site_options WHERE key_name = '$k'");
            $cfg->flushCache();
        } else {
            $cfg->setOption($k, $v);
        }
    }

    $w = new BotWorker();
    $schedule = new ReflectionMethod(BotWorker::class, 'memoryScribeSchedule');
    $schedule->setAccessible(true);
    $process = new ReflectionMethod(BotWorker::class, 'memoryScribeProcess');
    $process->setAccessible(true);

    // 1) Маркера нет -> инициализация MAX(id) без job (бэкфилл исключён)
    $mBefore = $ins($humanId, 'старое сообщение до внедрения памяти');
    $schedule->invoke($w);
    check($myScribeJobs() === 0, 'маркера не было: job не создан');
    check((int)$cfg->getOption('bot_memory_last_id', -1) >= $mBefore, 'маркер инициализирован текущим MAX(id)');

    // 2) Нет новых сообщений -> job не создаётся (AC-6 негатив, «тихая неделя»)
    $schedule->invoke($w);
    check($myScribeJobs() === 0, 'новых сообщений нет: job не создан');

    // 3) Только сообщения бота -> маркер сдвинут БЕЗ job и БЕЗ LLM
    $botMsg = $ins($botId, 'спонтанная реплика бота');
    $schedule->invoke($w);
    check($myScribeJobs() === 0, 'только бот: job не создан');
    check((int)$cfg->getOption('bot_memory_last_id', -1) >= $botMsg, 'только бот: маркер сдвинут (не застревает)');

    // 4) Интервал не истёк + новые ЕСТЬ -> job не создаётся (анти-вырожденность догона)
    $cfg->setOption('bot_memory_last_run', (string)time());
    $ins($humanId, "{$marker} говорит что-то новое");
    $schedule->invoke($w);
    check($myScribeJobs() === 0, 'интервал не истёк: job не создан несмотря на новые сообщения');

    // 5) Догон: backlog=1 разрешает через 600с (но не раньше)
    $cfg->setOption('bot_memory_backlog', '1');
    $cfg->setOption('bot_memory_last_run', (string)(time() - 500));
    $schedule->invoke($w);
    check($myScribeJobs() === 0, 'догон: 500с < 600с — рано');
    $cfg->setOption('bot_memory_last_run', (string)(time() - 700));
    $schedule->invoke($w);
    check($myScribeJobs() === 1, 'догон: 700с >= 600с — job создан');
    check((int)$cfg->getOption('bot_memory_last_run', 0) > time() - 5, 'last_run записан ДО enqueue (анти-дубль)');

    // 6) hasPending (pending) блокирует дубль
    $schedule->invoke($w);
    check($myScribeJobs() === 1, 'pending job блокирует создание второго');

    // 7) hasReactiveDue: pending mention с БУДУЩИМ run_after виден гейту; machine_spirit — нет
    $q = new JobQueue();
    $conn->query("INSERT INTO llm_jobs (type, payload, run_after, status, created_at) VALUES ('mention', '{}', DATE_ADD(NOW(), INTERVAL 30 SECOND), 'pending', NOW())");
    $mentionJobId = (int)$conn->insert_id;
    check($q->hasReactiveDue() === true, 'pending mention с будущим run_after — «ждущий» для гейта');
    // scribe при живом бэклоге не забирается
    $process->invoke($w);
    $st = $conn->query("SELECT status FROM llm_jobs WHERE type='memory_scribe' AND id > $baseJobId")->fetch_assoc()['status'];
    check($st === 'pending', 'реактивный бэклог не пуст: scribe не взят в работу');
    $conn->query("DELETE FROM llm_jobs WHERE id = $mentionJobId");
    $conn->query("INSERT INTO llm_jobs (type, payload, run_after, status, created_at) VALUES ('machine_spirit', '{}', NOW(), 'pending', NOW())");
    $spiritJobId = (int)$conn->insert_id;
    check($q->hasReactiveDue() === false, 'осиротевший pending machine_spirit НЕ блокирует scribe');
    $conn->query("DELETE FROM llm_jobs WHERE id = $spiritJobId");

    // 8) Выключатель гасит уже поставленный job: consume без LLM (rollback-рычаг, AC-8)
    $cfg->setOption('ai_memory_enabled', '0');
    $process->invoke($w);
    $st = $conn->query("SELECT status FROM llm_jobs WHERE type='memory_scribe' AND id > $baseJobId")->fetch_assoc()['status'];
    check($st === 'done', 'enabled=0: pending job законсьюмлен без работы');
    $cfg->setOption('ai_memory_enabled', '1');

    // 9) runScribe с фейковым провайдером: записи auto, маркер, backlog-флаг
    $llm = new LLM\LLMManager();
    $fake = new class implements LLM\LLMProviderInterface {
        public $reply = '';
        public int $calls = 0;
        public function askChat(array $messagesContext, string $systemPrompt): ?string {
            $this->calls++;
            return $this->reply;
        }
    };
    $rp = new ReflectionProperty(LLM\LLMManager::class, 'providers');
    $rp->setAccessible(true);
    $rp->setValue($llm, [$fake]);

    $markerBefore = (int)$cfg->getOption('bot_memory_last_id', 0);
    $mNew = $ins($humanId, "{$marker}_Пони любит тестировать автопись");
    // Ник в транскрипте — актуальный (COALESCE nickname), а не снапшот из chat_messages.
    $fake->reply = "ДОСЬЕ @{$marker}_Пони: любит тестировать автопись\nМЕМ: автопись-легенда {$marker}\nЛишняя болтовня модели";
    $scribe = new MemoryScribe($llm);
    $scribe->runScribe([]);
    $res = $conn->query("SELECT kind, source, user_id, text FROM bot_memory WHERE text LIKE '%автопись%' ORDER BY id");
    $recs = [];
    while ($row = $res->fetch_assoc()) $recs[] = $row;
    check(count($recs) === 2, 'runScribe: 2 записи из валидных строк (болтовня бракована)');
    check($recs[0]['source'] === 'auto' && (int)$recs[0]['user_id'] === $humanId, 'досье: auto, user_id участника батча');
    check((int)$cfg->getOption('bot_memory_last_id', 0) >= $mNew, 'маркер сдвинут на батч');
    check((string)$cfg->getOption('bot_memory_backlog', '?') === '0', 'батч неполный: backlog=0');

    // 10) Повторный прогон того же состояния: новых нет -> ничего не дублируется
    $scribe->runScribe([]);
    $c = (int)$conn->query("SELECT COUNT(*) c FROM bot_memory WHERE text LIKE '%автопись%'")->fetch_assoc()['c'];
    check($c === 2, 'повторный прогон: дублей нет (маркер перечитан из опций)');

    // 11) null от LLM -> исключение, маркер на месте
    $ins($humanId, "{$marker} ещё одно сообщение");
    $fake->reply = null;
    $markerBefore = (int)$cfg->getOption('bot_memory_last_id', 0);
    $threw = false;
    try { $scribe->runScribe([]); } catch (\RuntimeException $e) { $threw = true; }
    check($threw, 'null от LLM: исключение (fail, не complete)');
    check((int)$cfg->getOption('bot_memory_last_id', 0) === $markerBefore, 'null от LLM: маркер НЕ сдвинут (батч повторится)');

    // 12) Сжатие не трогает manual (AC-7): забиваем auto-досье сверх лимита
    $bm = new Domain\BotMemoryManager();
    $manualId = $bm->add('dossier', $humanId, 'ручная запись — неприкосновенна', 'manual');
    for ($i = 0; $i < 3; $i++) {
        $bm->add('dossier', $humanId, str_repeat("факт{$i} ", 40), 'auto'); // ~240 симв. каждая
    }
    // Сжатие проверяем НАПРЯМУЮ (не через runScribe: его мем-ветка при случайном
    // превышении порога сделала бы глобальный DELETE auto-мемов в общей докер-БД).
    $fake->reply = "сжатое авто-досье {$marker}";
    $compress = new ReflectionMethod(MemoryScribe::class, 'compressIfNeeded');
    $compress->setAccessible(true);
    $compress->invoke($scribe, [$humanId], time());
    $rows = $bm->getByUser($humanId);
    $manualLeft = array_values(array_filter($rows, fn($r) => (int)$r['id'] === $manualId));
    $autoLeft = array_values(array_filter($rows, fn($r) => $r['source'] === 'auto'));
    check(count($manualLeft) === 1 && $manualLeft[0]['text'] === 'ручная запись — неприкосновенна', 'AC-7: manual не тронут сжатием');
    check(count($autoLeft) === 1 && str_contains($autoLeft[0]['text'], 'сжатое авто-досье'), 'AC-7: auto-часть заменена одной сжатой записью');

    // 12б) Коллизия ников: тёзка в батче -> ник исключается из карты, досье не пишется
    $twinId = $mk("{$marker}_twin", "{$marker}_Пони"); // тот же nickname, другой user_id
    $collMarkerBefore = (int)$cfg->getOption('bot_memory_last_id', 0);
    $ins($humanId, "{$marker} говорит первый");
    $ins($twinId, "{$marker} говорит тёзка");
    $cntBefore = (int)$conn->query("SELECT COUNT(*) c FROM bot_memory WHERE kind='dossier'")->fetch_assoc()['c'];
    $fake->reply = "ДОСЬЕ @{$marker}_Пони: факт от неоднозначного ника";
    $scribe->runScribe([]);
    $cntAfter = (int)$conn->query("SELECT COUNT(*) c FROM bot_memory WHERE kind='dossier'")->fetch_assoc()['c'];
    check($cntAfter === $cntBefore, 'коллизия ников в батче: досье НЕ создано (ник исключён из карты)');
    check((int)$cfg->getOption('bot_memory_last_id', 0) > $collMarkerBefore, 'коллизия: маркер сдвинут (батч обработан, строка бракована)');

    // 13) failStale реанимирует зависший processing
    $conn->query("INSERT INTO llm_jobs (type, payload, run_after, status, attempts, created_at, claimed_at) VALUES ('memory_scribe', '{}', NOW(), 'processing', 0, NOW(), DATE_SUB(NOW(), INTERVAL 2000 SECOND))");
    $staleId = (int)$conn->insert_id;
    check($q->hasPending('memory_scribe') === true, 'зависший processing виден hasPending (блокирует дубли)');
    $q->failStale('memory_scribe', 1800);
    $st = $conn->query("SELECT status, attempts FROM llm_jobs WHERE id = $staleId")->fetch_assoc();
    check($st['status'] === 'failed' && (int)$st['attempts'] === 1, 'failStale: processing старше порога -> failed, attempts+1');
    $conn->query("DELETE FROM llm_jobs WHERE id = $staleId");

} finally {
    $conn->query("DELETE FROM llm_jobs WHERE id > $baseJobId AND type IN ('memory_scribe','mention','machine_spirit')");
    if (!empty($cleanupUserIds)) {
        $ids = implode(',', array_map('intval', $cleanupUserIds));
        $conn->query("DELETE FROM bot_memory WHERE user_id IN ($ids)");
        $conn->query("DELETE FROM users WHERE id IN ($ids)");
    }
    $conn->query("DELETE FROM bot_memory WHERE text LIKE '%{$marker}%'");
    if (!empty($cleanupMsgIds)) {
        $conn->query("DELETE FROM chat_messages WHERE id IN (" . implode(',', array_map('intval', $cleanupMsgIds)) . ")");
    }
    if (!empty($optBackup)) {
        $cfgFin = \Infra\ConfigManager::getInstance();
        foreach ($optBackup as $k => $v) {
            if ($v === null) {
                $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
            } else {
                $cfgFin->setOption($k, (string)$v);
            }
        }
        $cfgFin->flushCache();
    }
}

it_done();
