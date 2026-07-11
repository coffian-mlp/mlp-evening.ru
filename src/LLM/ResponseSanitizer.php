<?php

/**
 * Очистка ответа LLM + guard против «прорыва системных сообщений» в чат.
 *
 * Выделено из LLMManager отдельным классом без зависимостей (БД/сети),
 * чтобы покрывать юнит-тестами и переиспользовать.
 */
class ResponseSanitizer {

    /**
     * @return string|null  очищенный текст; '' если ответ оказался чистым прорывом
     *                      системного текста (постить нельзя); null если на вход пришёл null.
     */
    public static function clean($response, $botNickname = '', $botLogin = '') {
        if ($response === null) return null;
        $text = trim($response);
        if ($text === '') return '';

        // Признак следов служебного текста — для финальной проверки короткого остатка.
        $leakSignal = (bool)preg_match('/текст реплики|мета-текст|\[Систем|Контекст:|Не пиши никаких пояснений|НИКОГДА не добавляй|Проанализируй последние/iu', $text);

        // (1) Отрезаем всё, начиная с маркеров служебных врезок/дампа контекста.
        $cutMarkers = ['[Системное правило]', '[Система]', '[Инструкция', '[Задача]', 'Контекст:', '=== ВАЖНОЕ СИСТЕМНОЕ'];
        foreach ($cutMarkers as $mk) {
            $pos = mb_stripos($text, $mk, 0, 'UTF-8');
            if ($pos !== false) {
                $text = trim(mb_substr($text, 0, $pos, 'UTF-8'));
            }
        }

        // (2) Удаляем целые предложения-эхо самой инструкции.
        $echoPhrases = [
            'текст реплики', 'мета-текст', 'Пиши ТОЛЬКО текст', 'НИКОГДА не добавляй',
            'Не пиши никаких пояснений', 'ответь ровно одним словом', 'Проанализируй последние',
        ];
        foreach ($echoPhrases as $ph) {
            $text = preg_replace('/[^.!?\n]*' . preg_quote($ph, '/') . '[^.!?\n]*[.!?]?\s*/iu', '', $text);
        }
        $text = trim($text);

        // (3) Срезаем префиксы вида "[HH:MM] Имя:" и имя бота в начале.
        $text = self::stripSpeakerPrefix(trim($text), $botNickname, $botLogin);

        // (4) Обёртка кавычками + HTML-сущности (ChatManager при сохранении снова экранирует).
        $text = preg_replace('/^"(.*)"$/us', '$1', trim($text));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($text);

        // (5) Если были явные следы системщины, а остался лишь короткий огрызок
        //     (например "Поняла?") — это был прорыв целиком, не постим.
        if ($leakSignal && mb_strlen($text, 'UTF-8') < 15) {
            return '';
        }

        return $text;
    }

    private static function stripSpeakerPrefix($s, $botNickname, $botLogin) {
        $s = preg_replace('/^\[\d{1,2}:\d{2}\]\s*[^:\n]{1,40}:\s*/u', '', $s);
        if ($botNickname !== '') {
            $s = preg_replace('/^' . preg_quote($botNickname, '/') . ':\s*/iu', '', $s);
            $first = explode(' ', $botNickname)[0] ?? '';
            if ($first !== '') $s = preg_replace('/^' . preg_quote($first, '/') . ':\s*/iu', '', $s);
        }
        if ($botLogin !== '') {
            $s = preg_replace('/^' . preg_quote($botLogin, '/') . ':\s*/iu', '', $s);
        }
        return preg_replace('/^\[\d{1,2}:\d{2}\]\s*[^:\n]{1,40}:\s*/u', '', $s);
    }
}
