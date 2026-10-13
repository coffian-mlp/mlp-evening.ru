<?php

namespace LLM;

/**
 * Извлекает маркер реакции из ответа модели: [РЕАКЦИЯ: type] (или REACT/REACTION).
 * Позволяет боту поставить реакцию на сообщение вместо/вместе с текстовым ответом.
 * Чистая логика без зависимостей — покрыта юнит-тестами.
 */
class ReactionParser {

    // Должно совпадать с $allowed в ChatManager::toggleReaction().
    const ALLOWED = ['like', 'dislike', 'laugh', 'cry', 'neutral', 'heart', 'fire', 'wow', 'think', 'party', 'cool', 'eyes', 'skull', 'clown', 'hundred', 'poop', 'eggplant', 'hand', 'anchor', 'pie'];

    public static function targets(array $context): array {
        $targets = [];
        foreach ($context as $entry) {
            if (($entry['role'] ?? '') === 'user' && (int)($entry['chat_message_id'] ?? 0) > 0) {
                $targets[(int)$entry['chat_message_id']] = mb_substr((string)$entry['content'], 0, 240);
            }
        }
        return $targets;
    }

    public static function instruction(array $context): string {
        $targets = self::targets($context);
        $prompt = "\n\n[Реакции]: по желанию добавь [РЕАКЦИЯ: X ID], где X: " . implode(', ', self::ALLOWED)
            . '. ID выбирай только из серверного списка ниже. Можно отреагировать на любое из этих сообщений; не выдумывай ID. '
            . 'Старый маркер [РЕАКЦИЯ: X] означает реакцию на сообщение, на которое отвечаешь. '
            . 'Можно вернуть только маркер без слов. Ставь реакции по настроению, не каждый раз.';
        $prompt .= "\nМетки [№ID] перед текстом реплик обозначают серверный ID, это не часть имени или сообщения. Допустимые ID: " . implode(', ', array_keys($targets));
        return $prompt;
    }

    /** Invalid explicit target must never fall back to another message. */
    public static function target(array $parsed, array $context, ?int $default): ?int {
        if ($parsed['reaction'] === null) return null;
        if ($parsed['target'] !== null) return isset(self::targets($context)[$parsed['target']]) ? $parsed['target'] : null;
        return $default;
    }

    /**
     * @return array ['reaction' => ?string, 'text' => string] — валидная реакция (или null)
     *               и текст без маркеров реакции.
     */
    public static function extract(?string $text): array {
        if ($text === null) {
            return ['reaction' => null, 'target' => null, 'text' => ''];
        }
        $reaction = null; $target = null;
        if (preg_match('/\[\s*(?:РЕАКЦИЯ|REACTION|REACT)\s*:\s*([\p{L}]+)(?:\s+([1-9][0-9]{0,9}))?\s*\]/iu', $text, $m)) {
            $type = mb_strtolower(trim($m[1]), 'UTF-8');
            if (in_array($type, self::ALLOWED, true)) {
                $reaction = $type; $target = isset($m[2]) ? (int)$m[2] : null;
            }
        }
        // Убираем ВСЕ маркеры (в т.ч. невалидные), чтобы они не утекли в чат.
        $clean = preg_replace('/\[\s*(?:РЕАКЦИЯ|REACTION|REACT)\s*:\s*[^\]]*(?:\]|$)/iu', '', $text);
        return ['reaction' => $reaction, 'target' => $target, 'text' => trim($clean)];
    }
}
