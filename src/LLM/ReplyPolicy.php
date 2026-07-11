<?php

/**
 * Политика ответа бота (вариант A — адаптивный дебаунс + анти-спам).
 * Чистая логика без БД/сети — покрывается юнит-тестами.
 *
 * Решает по набору накопившихся упоминаний (группа дебаунса): отвечать ли,
 * одним адресным ответом / сводным, и ставить ли цитату (reply-to).
 */
class ReplyPolicy {

    /**
     * @param array $pending  список задач-упоминаний:
     *        [ ['message_id'=>?int, 'user_id'=>int, 'username'=>string, 'message'=>string], ... ]
     * @param array $cfg      ['spam_threshold'=>int, 'reply_min_gap'=>int, 'now'=>int, 'last_bot_reply_ts'=>?int]
     * @return array решение:
     *        ['action'=>'reply'|'skip', 'reason'=>string, 'mode'=>?'single'|'address_all'|'coalesce',
     *         'quote_message_id'=>?int, 'askers'=>string[]]
     */
    public static function decide(array $pending, array $cfg): array {
        $spamThreshold = (int)($cfg['spam_threshold']   ?? 4);
        $replyMinGap   = (int)($cfg['reply_min_gap']     ?? 20);
        $now           = (int)($cfg['now']               ?? 0);
        $lastBotReply  = $cfg['last_bot_reply_ts'] ?? null;

        if (empty($pending)) {
            return self::skip('no_jobs');
        }

        // Анти-спам: если бот только что отвечал — держим паузу (при нагрузке отвечаем не всем).
        if ($lastBotReply !== null && ($now - (int)$lastBotReply) < $replyMinGap) {
            return self::skip('rate_limited');
        }

        // Уникальные адресаты (по username, в порядке появления).
        $askers = [];
        foreach ($pending as $p) {
            $u = trim((string)($p['username'] ?? ''));
            if ($u !== '' && !in_array($u, $askers, true)) {
                $askers[] = $u;
            }
        }

        $count = count($pending);

        // Ровно одно упоминание → адресный 1:1, СТАВИМ цитату.
        if ($count === 1) {
            return [
                'action'           => 'reply',
                'reason'           => 'single',
                'mode'             => 'single',
                'quote_message_id' => $pending[0]['message_id'] ?? null,
                'askers'           => $askers,
            ];
        }

        // Тихо (немного упоминаний) → один живой ответ, адресуем всех спросивших. Без цитаты.
        if ($count <= $spamThreshold) {
            return [
                'action'           => 'reply',
                'reason'           => 'quiet_address_all',
                'mode'             => 'address_all',
                'quote_message_id' => null,
                'askers'           => $askers,
            ];
        }

        // Спам → сводный ответ на актуальную беседу. Без цитаты, адресуем максимум нескольких.
        return [
            'action'           => 'reply',
            'reason'           => 'spam_coalesce',
            'mode'             => 'coalesce',
            'quote_message_id' => null,
            'askers'           => array_slice($askers, 0, 3),
        ];
    }

    private static function skip(string $reason): array {
        return ['action' => 'skip', 'reason' => $reason, 'mode' => null, 'quote_message_id' => null, 'askers' => []];
    }

    /**
     * Доп. инструкция для модели под режим ответа (адресация/сводность).
     * Пустая строка для single — отвечаем на последнее сообщение как обычно.
     * Чистая функция — покрыта тестами.
     */
    public static function instruction(array $decision): string {
        $mode   = $decision['mode'] ?? null;
        $askers = $decision['askers'] ?? [];

        if ($mode === 'address_all' && !empty($askers)) {
            $list = implode(', ', array_map(static fn($u) => '@' . $u, $askers));
            return "Тебя в чате упомянули несколько человек: {$list}. "
                 . "Ответь им в ОДНОМ сообщении, обратившись к каждому через @. "
                 . "Не пиши несколько отдельных реплик подряд.";
        }
        if ($mode === 'coalesce') {
            return "Тебя сейчас упоминают очень часто. Не отвечай каждому по отдельности — "
                 . "напиши ОДНО короткое общее сообщение по текущей теме беседы.";
        }
        return '';
    }
}
