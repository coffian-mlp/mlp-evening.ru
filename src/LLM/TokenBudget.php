<?php

namespace LLM;

use Infra\ConfigManager;

/**
 * Бюджет ответа LLM (MLP-348): потолок completion-токенов из настройки дашборда
 * `ai_max_tokens` и распознавание ответа, оборванного этим потолком.
 *
 * У reasoning-моделей (z-ai/glm-5.3 и её flashx) рассуждения расходуют тот же
 * max_tokens, что и текст ответа (MLP-347: при 2000 сцены режиссёра обрывались на
 * полуслове). Оборванный ответ API помечает finish_reason = "length" — такой ответ
 * нельзя выдавать за готовый: провайдер бросает TruncatedResponseException.
 */
final class TokenBudget {
    /** Потолок по умолчанию (MLP-347, решение владельца). */
    public const DEFAULT = 5000;
    /** Ниже — обрывы даже у моделей без рассуждений (сжатие памяти пишет до ~1600 токенов). */
    public const MIN = 1000;
    /** Выше — защита от опечатки в дашборде; реальный предел задаёт модель. */
    public const MAX = 32000;

    /** Pure: значение настройки → потолок в допустимых границах; 0, пусто и мусор — по умолчанию. */
    public static function resolve($raw): int {
        $value = is_numeric($raw) ? (int)$raw : 0;
        if ($value <= 0) {
            return self::DEFAULT;
        }
        return max(self::MIN, min(self::MAX, $value));
    }

    /** Потолок для запроса к OpenAI-совместимому провайдеру (RouterAI, OpenRouter, OpenAI). */
    public static function fromConfig(): int {
        return self::resolve(ConfigManager::getInstance()->getOption('ai_max_tokens', self::DEFAULT));
    }

    /**
     * Pure: текст ответа OpenAI-совместимого API (choices[0]).
     * finish_reason = "length" → TruncatedResponseException с полученным обрубком (у reasoning-моделей
     * он часто пустой: весь бюджет ушёл на рассуждения). Текста нет → null, и провайдер бросает
     * своё «Invalid Response», как раньше.
     */
    public static function contentOf(array $decoded, string $provider): ?string {
        $choice = $decoded['choices'][0] ?? null;
        if (!is_array($choice)) {
            return null;
        }
        $content = $choice['message']['content'] ?? null;
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new TruncatedResponseException($provider, is_string($content) ? trim($content) : '');
        }
        return is_string($content) ? trim($content) : null;
    }
}
