<?php

// Этот скрипт предназначен для запуска через cron (например, раз в 5-10 минут)
// * /5 * * * * php /path/to/mlp-evening/cron_llm.php

require_once __DIR__ . '/src/LLM/LLMManager.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ConfigManager.php';
require_once __DIR__ . '/src/EpisodeManager.php';

// Логирование запусков
$logFile = __DIR__ . '/cron_llm.log';
$timestamp = date('Y-m-d H:i:s');

function logMessage($file, $time, $msg) {
    file_put_contents($file, "[$time] $msg\n", FILE_APPEND);
    echo $msg . "\n";
}

$llm = new LLMManager();
$db = Database::getInstance()->getConnection();
$config = ConfigManager::getInstance();

$announcedEventsJson = $config->getOption('announced_events', '{}');
$announcedEvents = json_decode($announcedEventsJson, true) ?: [];

// --- Магия Календаря (Анонсы и Завершения) ---
// Получаем все события
$stmt = $db->prepare("SELECT * FROM events");
$stmt->execute();
$res = $stmt->get_result();
$events = [];
while ($row = $res->fetch_assoc()) {
    $events[] = $row;
}

$now = time();
$horizonStr = gmdate('Y-m-d H:i:s', $now + 86400 * 7); // Смотрим на неделю вперед для расширения
$expandedEvents = [];

// Разворачиваем регулярные события на ближайшую неделю, чтобы найти те, что проходят сегодня
foreach ($events as $evt) {
    $utcStart = strtotime($evt['start_time'] . ' UTC');
    $expandedEvents[] = array_merge($evt, ['real_start_time' => $utcStart, 'run_id' => $evt['id'] . '_' . $utcStart]);
    
    if ($evt['is_recurring']) {
        $nextDate = $utcStart;
        while ($nextDate < $now + 86400 * 7) {
            if ($evt['recurrence_rule'] === 'daily') {
                $nextDate += 86400;
            } elseif ($evt['recurrence_rule'] === 'weekly') {
                $nextDate += 86400 * 7;
            } else {
                break;
            }
            if ($nextDate < $now + 86400 * 7) {
                $expandedEvents[] = array_merge($evt, ['real_start_time' => $nextDate, 'run_id' => $evt['id'] . '_' . $nextDate]);
            }
        }
    }
}

// Сортируем
usort($expandedEvents, function($a, $b) {
    return $a['real_start_time'] <=> $b['real_start_time'];
});

$announcementSent = false;

foreach ($expandedEvents as $evt) {
    $start = $evt['real_start_time'];
    $end = $start + ($evt['duration_minutes'] * 60);
    $runId = $evt['run_id'];
    
    $minutesToStart = ($start - $now) / 60;
    $minutesSinceEnd = ($now - $end) / 60;
    
    // 1. Анонс за 60 минут
    if ($minutesToStart > 0 && $minutesToStart <= 60 && empty($announcedEvents[$runId]['60m'])) {
        $llm->processTrigger('schedule_command', [
            'message' => "Напиши анонс, что через час начнется событие '{$evt['title']}'."
        ]);
        $announcedEvents[$runId]['60m'] = true;
        $announcementSent = true;
        logMessage($logFile, $timestamp, "Отправлен анонс (60м) для события {$evt['title']}");
    }
    
    // 2. Анонс за 15 минут
    if ($minutesToStart > 0 && $minutesToStart <= 15 && empty($announcedEvents[$runId]['15m'])) {
        $llm->processTrigger('schedule_command', [
            'message' => "Напиши срочный анонс, что событие '{$evt['title']}' начнется уже через 15 минут!"
        ]);
        $announcedEvents[$runId]['15m'] = true;
        $announcementSent = true;
        logMessage($logFile, $timestamp, "Отправлен анонс (15м) для события {$evt['title']}");
    }
    
    // 3. Завершение и генерация плейлиста
    if ($minutesSinceEnd >= 0 && $minutesSinceEnd <= 10 && empty($announcedEvents[$runId]['finished'])) {
        $finishMsg = "Спасибо всем за просмотр! Вечерок подошел к концу.";
        
        if ($evt['generate_new_playlist']) {
            $epManager = new EpisodeManager();
            $epManager->regeneratePlaylist();
            $finishMsg .= " А вот и расписание на следующий раз! Пожалуйста, напиши об этом в чат в своем стиле.";
        } else {
            $finishMsg .= " Напиши об этом в чат тепло и дружелюбно.";
        }
        
        // Для завершения события мы можем использовать тот же триггер, он сам прикрепит новый плейлист
        $llm->processTrigger('schedule_command', [
            'message' => $finishMsg
        ]);
        
        $announcedEvents[$runId]['finished'] = true;
        $announcementSent = true;
        logMessage($logFile, $timestamp, "Событие {$evt['title']} завершено. Плейлист: " . ($evt['generate_new_playlist'] ? 'Сгенерирован' : 'Нет'));
    }
}

// Очистка старых логов анонсов (оставляем только последние 50)
if (count($announcedEvents) > 50) {
    $announcedEvents = array_slice($announcedEvents, -50, null, true);
}
$config->setOption('announced_events', json_encode($announcedEvents));

// --- Спонтанное общение (если не было анонсов) ---
if (!$announcementSent) {
    $result = $llm->processTrigger('cron_spontaneous');
    
    if ($result) {
        logMessage($logFile, $timestamp, "Спонтанное сообщение отправлено.");
    } else {
        logMessage($logFile, $timestamp, "Пони решила промолчать (или бот отключен/чат пуст).");
    }
}
