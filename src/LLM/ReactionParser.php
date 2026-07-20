<?php

namespace LLM;

use Domain\ChatManager;

/**
 * Извлекает маркер реакции из ответа модели: [РЕАКЦИЯ: type] (или REACT/REACTION).
 * Позволяет боту поставить реакцию на сообщение вместо/вместе с текстовым ответом.
 * Чистая логика без зависимостей — покрыта юнит-тестами.
 */
class ReactionParser {

    // Должно совпадать с $allowed в ChatManager::toggleReaction().
    const ALLOWED = ['like', 'dislike', 'laugh', 'cry', 'neutral', 'heart', 'fire', 'wow', 'think', 'party', 'cool'];

    /**
     * @return array ['reaction' => ?string, 'text' => string] — валидная реакция (или null)
     *               и текст без маркеров реакции.
     */
    public static function extract(?string $text): array {
        if ($text === null) {
            return ['reaction' => null, 'text' => ''];
        }
        $reaction = null;
        if (preg_match('/\[\s*(?:РЕАКЦИЯ|REACTION|REACT)\s*:\s*([\p{L}]+)\s*\]/iu', $text, $m)) {
            $type = mb_strtolower(trim($m[1]), 'UTF-8');
            if (in_array($type, self::ALLOWED, true)) {
                $reaction = $type;
            }
        }
        // Убираем ВСЕ маркеры (в т.ч. невалидные), чтобы они не утекли в чат.
        $clean = preg_replace('/\[\s*(?:РЕАКЦИЯ|REACTION|REACT)\s*:\s*[^\]]*\]/iu', '', $text);
        return ['reaction' => $reaction, 'text' => trim($clean)];
    }
}
