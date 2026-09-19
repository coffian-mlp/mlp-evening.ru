<?php

namespace LLM;

use Domain\BotMemoryManager;

/**
 * «Кто сейчас в чате» для контекста бота (MLP-323). Pure: вход — users/guests из
 * OnlineManager::getOnlineStats(), выход — фоновая строка присутствия и расширенный
 * список id для блока памяти.
 *
 * Без этого молчун выпадал из окна контекста вместе с досье, и бот считал, что его нет
 * в чате. Окно присутствия — то же, что у сайдбара (WINDOW_MIN): Лира видит ровно то,
 * что видят зрители.
 */
final class OnlineContext {
    public const WINDOW_MIN = 3;
    public const MARKER = '[Кто сейчас в чате]';

    /**
     * Фоновая строка присутствия; null — в чате никого (кроме бота).
     * @param array $users [['id' => .., 'nickname' => ..], ...] — getOnlineStats()['users']
     */
    /** Окно «в чате N» (MLP-331): первая реплика старше — «больше 8 часов». */
    public const ACTIVITY_HOURS = 8;
    /** Пауза, после которой к участнику приписывается «молчит N» (MLP-331). */
    public const SILENT_AFTER_SEC = 30 * 60;

    /**
     * @param array $activity [user_id => ['first' => ts, 'last' => ts]] — первая/последняя реплика за сутки
     *                        (ChatManager::getActivityByUsers); пусто = без пометок (старый формат)
     */
    public static function line(array $users, int $guests, int $botId, array $activity = [], ?int $now = null): ?string {
        $now = $now ?? time();
        $nicks = [];
        foreach ($users as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id === $botId) continue;
            // Ник — недоверенный ввод: нормализация держит строку однострочной и без скобок-инструкций.
            $nick = BotMemoryManager::normalizeText((string)($u['nickname'] ?? ''));
            if ($nick === '') continue;
            if ($activity) {
                $nick .= ' (' . self::activityLabel($activity[$id] ?? null, $now) . ')';
            }
            $nicks[] = $nick;
        }
        if (!$nicks && $guests <= 0) return null;
        $s = self::MARKER . ': ' . ($nicks ? implode(', ', $nicks) : 'зарегистрированных нет');
        if ($guests > 0) $s .= '; гостей: ' . $guests;
        $s .= '. Это фон: кто-то из них молчит, но они здесь — не окликай молчунов без повода и не зачитывай этот список.';
        if ($activity) {
            $s .= ' В скобках — как давно человек пишет в чате и сколько молчит: давний участник, даже если его реплик не видно, — не новичок, не приветствуй его как вошедшего.';
        }
        return $s;
    }

    /** Pure: «в чате 4 ч 12 мин[, молчит 50 мин]» / «в чате больше 8 часов» / «без реплик» (MLP-331). */
    public static function activityLabel(?array $a, int $now): string {
        if (!$a || empty($a['first'])) return 'без реплик';
        $first = (int)$a['first'];
        $last  = (int)($a['last'] ?? $first);
        $label = ($now - $first) >= self::ACTIVITY_HOURS * 3600
            ? 'в чате больше ' . self::ACTIVITY_HOURS . ' часов'
            : 'в чате ' . MskClock::delta($now - $first);
        if ($now - $last >= self::SILENT_AFTER_SEC) {
            $label .= ', молчит ' . MskClock::delta($now - $last);
        }
        return $label;
    }

    /**
     * Порядок id для блока памяти: говорившие (как переданы) + онлайн-молчуны в хвост,
     * бот исключён. Бюджеты блока не меняются — молчуны подмешиваются, пока есть место.
     */
    public static function appendSilent(array $speakerIds, array $onlineIds, int $botId): array {
        $ids = [];
        foreach ([$speakerIds, $onlineIds] as $list) {
            foreach ($list as $id) {
                $id = (int)$id;
                if ($id > 0 && $id !== $botId && !in_array($id, $ids, true)) $ids[] = $id;
            }
        }
        return $ids;
    }
}
