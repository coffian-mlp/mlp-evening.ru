<?php

namespace LLM;

use ConfigManager;


/**
 * Продюсер реактивных триггеров бота. Решает: положить в очередь (воркер ответит)
 * или обработать inline в текущем процессе (прежнее поведение, фоллбек для слабого хостинга).
 *
 * Уровни (ai_worker_mode): daemon/cron/auto → очередь; inline или отсутствие живого
 * воркера в auto → inline. Мастер-флаг ai_use_queue=0 → всегда inline (как раньше).
 */
class BotDispatch {

    /** Диспетчеризация триггера: очередь или inline (с lifelike-задержкой). */
    public static function dispatch(string $type, array $payload): void {

        // ГЕЙТ: на обычное сообщение бот реагирует, ТОЛЬКО если к нему обратились
        // (@упоминание, алиас, цитата). Иначе — молчим (не ставим задачу, не отвечаем).
        // Команды и приветствия проходят всегда.
        if ($type === 'mention') {
            $probe = new LLMManager();
            if (!$probe->messageAddressesBot($payload['message'] ?? '', $payload['quoted_msg_ids'] ?? [])) {
                return;
            }
        }

        if (self::shouldQueue()) {
            (new JobQueue())->enqueue($type, $payload, self::delaySeconds());
            return;
        }
        // Inline-фоллбек: прежнее поведение — «раздумье» + синхронная обработка.
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        @ignore_user_abort(true);
        sleep(self::delaySeconds());
        (new LLMManager())->processTrigger($type, $payload);
    }

    /** Идёт ли ответ через очередь (иначе inline). */
    public static function shouldQueue(): bool {
        $c = ConfigManager::getInstance();
        if (!$c->getOption('ai_use_queue', 0)) {
            return false; // мастер-флаг выключен → строго прежнее поведение
        }
        $mode = $c->getOption('ai_worker_mode', 'auto');
        if ($mode === 'inline') {
            return false;
        }
        if ($mode === 'auto' && self::workerStale($c)) {
            return false; // нет живого воркера → деградация в inline
        }
        return true; // daemon / cron / auto+живой воркер
    }

    /** lifelike-задержка «на подумать», сек (диапазон из настроек). */
    public static function delaySeconds(): int {
        $c = ConfigManager::getInstance();
        $min = (int)$c->getOption('ai_delay_min', 4);
        $max = (int)$c->getOption('ai_delay_max', 42);
        return ($max > $min) ? rand($min, $max) : max(0, $min);
    }

    private static function workerStale(ConfigManager $c): bool {
        $hb = (int)$c->getOption('bot_worker_heartbeat', 0);
        return (time() - $hb) > 90; // >90с без тика = воркера нет
    }
}
