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
    public static function line(array $users, int $guests, int $botId): ?string {
        $nicks = [];
        foreach ($users as $u) {
            if ((int)($u['id'] ?? 0) === $botId) continue;
            // Ник — недоверенный ввод: нормализация держит строку однострочной и без скобок-инструкций.
            $nick = BotMemoryManager::normalizeText((string)($u['nickname'] ?? ''));
            if ($nick !== '') $nicks[] = $nick;
        }
        if (!$nicks && $guests <= 0) return null;
        $s = self::MARKER . ': ' . ($nicks ? implode(', ', $nicks) : 'зарегистрированных нет');
        if ($guests > 0) $s .= '; гостей: ' . $guests;
        return $s . '. Это фон: кто-то из них молчит, но они здесь — не окликай молчунов без повода и не зачитывай этот список.';
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
