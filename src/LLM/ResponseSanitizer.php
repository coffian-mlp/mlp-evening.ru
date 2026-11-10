<?php

namespace LLM;

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
        $text = self::stripMessageIds(trim($response));
        if ($text === '') return '';

        // Признак следов служебного текста — для финальной проверки короткого остатка.
        $leakSignal = (bool)preg_match('/текст реплики|мета-текст|\[Систем|Контекст:|Не пиши никаких пояснений|НИКОГДА не добавляй|Проанализируй последние/iu', $text);

        // (1) Отрезаем всё, начиная с маркеров служебных врезок/дампа контекста.
        // MLP-314: '[Заметки Лиры' — маркер блока памяти; в leakSignal НЕ добавляется
        // (regex-alternation сломался бы о '[', а порог 15 симв. ел бы короткие ответы).
        $cutMarkers = ['[Системное правило]', '[Система]', '[Инструкция', '[Задача]', 'Контекст:', '=== ВАЖНОЕ СИСТЕМНОЕ', '[Заметки Лиры', '[Воспоминания]', '[К этой реплике]', '[Время]', '[Кто сейчас в чате]', '[Чат]:'];
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
        $text = self::stripMessageIds(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ($cutMarkers as $marker) { $position = mb_stripos($text, $marker); if ($position !== false) $text = trim(mb_substr($text, 0, $position)); }
        $text = self::stripSpeakerPrefix(trim($text), $botNickname, $botLogin);
        $text = trim($text);

        // (5) Если были явные следы системщины, а остался лишь короткий огрызок
        //     (например "Поняла?") — это был прорыв целиком, не постим.
        if ($leakSignal && mb_strlen($text, 'UTF-8') < 15) {
            return '';
        }

        return $text;
    }

    /** Reserved chat-source annotations must not become visible prose. */
    public static function stripMessageIds(string $text): string {
        return preg_replace('/\[\s*№\s*[0-9]+\s*\]\h*/u', '', $text) ?? $text;
    }

    /**
     * Pure (MLP-342): есть ли в ответе хоть что-то осмысленное — минимум два буквенных символа подряд.
     * Одинокая «[» (обрыв «[РЕАКЦИЯ: …», glm-5.3, 01:12 20.09) или пунктуация — не реплика.
     */
    public static function isMeaningful(?string $text): bool {
        return $text !== null && (bool)preg_match('/\p{L}{2,}|\p{N}{2,}/u', $text);
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

    /**
     * Pure (MLP-351): есть ли в тексте картинка, которую чат отрисует, — markdown-картинка
     * (любой адрес) или голая http(s)-ссылка на .jpg/.jpeg/.png/.gif/.webp (как автоэмбед
     * ChatManager::formatMessage). В ответе модели такая картинка — всегда выдумка: настоящие
     * рисунки код приклеивает к подписи уже после ответа (LyraArtist), а стикеры :код: сюда не относятся.
     */
    public static function hasImage(string $text): bool {
        return preg_match('/!\[[^\]]*\]\([^)\s]+\)/u', $text) === 1
            || preg_match('/https?:\/\/[^\s<]+\.(?:jpe?g|png|gif|webp)/iu', $text) === 1; // тот же шаблон, что у автоэмбеда чата
    }

    /**
     * Pure (MLP-356): латинские слова текста в нижнем регистре, без повторов. Не считаются: ссылки,
     * @упоминания, /команды, части короче 3 букв и аббревиатуры КАПСОМ до 5 букв (OBS, PHP, XD).
     * Слова через дефис разбираются по частям («sniff-sniff» → sniff).
     */
    public static function latinWords(string $text): array {
        $text = (string)preg_replace('~https?://\S+~u', ' ', $text);
        preg_match_all('~(?<![@/\w])[A-Za-z][A-Za-z\'\-]*~u', $text, $m);
        $out = [];
        foreach ($m[0] as $token) {
            foreach (explode('-', $token) as $part) {
                $part = trim($part, "'");
                if (strlen($part) < 3 || preg_match('~^[A-Z]{3,5}$~', $part)) {
                    continue;
                }
                $out[strtolower($part)] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Pure (MLP-356): латинские слова ответа, которых нет среди разрешённых — из реплик людей,
     * данных контекста и ников. Чужая латиница в реплике Лиры — повод для повторной генерации.
     */
    public static function foreignLatin(string $reply, array $allowed): array {
        $allowedSet = array_flip(array_map('strtolower', $allowed));
        return array_values(array_filter(self::latinWords($reply), static fn($w) => !isset($allowedSet[$w])));
    }

    /** Reply-only title exemptions (MLP-458); the shared summary parser stays strict. */
    public static function foreignLatinInReply(string $reply, array $allowed): array {
        // Exempt occurrences, never vocabulary. Regex failures retain the input of that stage.
        $mask = static fn($m) => strtr($m[0], 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', str_repeat(' ', 52));
        $text = preg_replace_callback('~https?://\\S+~u', static fn($m) => str_repeat(' ', strlen($m[0])), $reply) ?? $reply;
        $text = preg_replace_callback('/«[^»]*»|"[^"]*"|“[^”]*”|„[^“]*“/u', $mask, $text) ?? $text;
        $capital = "[A-Z][A-Za-z]*(?:['-][A-Za-z]+)*";
        $connector = '(?:a|an|the|and|or|of|in|on|at|to|for|from|with|without|by|as|is|vs|versus|into|over|under)';
        $boundary = "[\\p{L}\\p{N}_@/'-]";
        // Tokenize once instead of matching an unbounded nested connector chain.
        $pattern = '~(?<!' . $boundary . ')(?:' . $capital . '|' . $connector . ')(?!' . $boundary . ')~u';
        if (preg_match_all($pattern, $text, $tokens, PREG_OFFSET_CAPTURE) === false) return self::foreignLatin($text, $allowed);
        $spans = []; $start = null; $lastCapital = 0; $capitals = 0; $previousEnd = 0;
        $finish = static function () use (&$spans, &$start, &$lastCapital, &$capitals) {
            if ($capitals >= 2) $spans[] = [$start, $lastCapital];
            $start = null; $capitals = 0;
        };
        foreach ($tokens[0] as [$word, $offset]) {
            $gap = substr($text, $previousEnd, $offset - $previousEnd);
            if (strspn($gap, " \t") !== strlen($gap)) $finish();
            $previousEnd = $offset + strlen($word);
            if ($word[0] >= 'A' && $word[0] <= 'Z') {
                $start ??= $offset; $capitals++; $lastCapital = $previousEnd;
            }
        }
        $finish();
        $out = ''; $cursor = 0;
        foreach ($spans as [$start, $end]) {
            $out .= substr($text, $cursor, $start - $cursor) . $mask([substr($text, $start, $end - $start)]);
            $cursor = $end;
        }
        return self::foreignLatin($out . substr($text, $cursor), $allowed);
    }
}
