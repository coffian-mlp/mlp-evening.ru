<?php
namespace LLM;

/** Pure output-language checks. Identifiers and explicitly permitted quotes retain their bytes. */
final class CjkGuard {
    private const LETTERS = '\x{3005}-\x{3007}\x{3021}-\x{3029}\x{3038}-\x{303B}\x{3031}-\x{3035}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}'
        . '\x{20000}-\x{2EE5F}\x{2F800}-\x{2FA1F}\x{30000}-\x{323AF}'
        . '\x{3040}-\x{30FF}\x{31F0}-\x{31FF}\x{FF66}-\x{FF9F}\x{1AFF0}-\x{1AFFF}\x{1B000}-\x{1B16F}'
        . '\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{A960}-\x{A97F}\x{AC00}-\x{D7AF}\x{D7B0}-\x{D7FF}\x{FFA0}-\x{FFDC}';

    /** Only explicit quotes in the current human message, never historical model text. */
    public static function quoted(string $message): array {
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/«([^»\n]+)»|"([^"\n]+)"|“([^”\n]+)”|「([^」\n]+)」|『([^』\n]+)』/u', $message, $quotes, PREG_SET_ORDER);
        $allowed = [];
        foreach ($quotes as $quote) {
            foreach (array_slice($quote, 1) as $part) {
                preg_match_all('/[' . self::LETTERS . ']+/u', $part, $matches);
                $allowed = array_merge($allowed, $matches[0]);
            }
        }
        return array_values(array_unique($allowed));
    }

    public static function foreign(string $text, array $allowed = []): array {
        $found = [];
        self::prose($text, $allowed, static function (string $part) use (&$found): string {
            preg_match_all('/[' . self::LETTERS . ']+/u', $part, $matches);
            $found = array_merge($found, $matches[0]);
            return $part;
        });
        return array_values(array_unique($found));
    }

    /** Typography alone does not trigger a language retry. Do not normalize URLs or commands. */
    public static function normalize(string $text, array $allowed = []): string {
        return self::prose($text, $allowed, static function (string $part): string {
            $part = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', static fn($m) => mb_chr(mb_ord($m[0]) - 0xFEE0), $part);
            return strtr($part, ['　'=>' ', '。'=>'.', '、'=>',', '「'=>'«', '」'=>'»', '『'=>'«', '』'=>'»',
                '〈'=>'«', '〉'=>'»', '《'=>'«', '》'=>'»', '【'=>'[', '】'=>']', '〔'=>'[', '〕'=>']',
                '〖'=>'[', '〗'=>']', '〘'=>'[', '〙'=>']', '〚'=>'[', '〛'=>']', '〜'=>'~', '・'=>'·', '･'=>'·',
                '｟'=>'(', '｠'=>')', '￠'=>'¢', '￡'=>'£', '￢'=>'¬', '￣'=>'¯', '￤'=>'¦', '￥'=>'¥', '￦'=>'₩', '￨'=>'│', '￩'=>'←', '￪'=>'↑', '￫'=>'→', '￬'=>'↓', '￭'=>'■', '￮'=>'○']);
        });
    }

    /** Last-resort deletion, not translation: retain the best available prose and its identifiers. */
    public static function strip(string $text, array $allowed = []): string {
        $text = self::normalize($text, $allowed);
        return trim(self::prose($text, $allowed, static function (string $part): string {
            $part = preg_replace('/[' . self::LETTERS . ']+/u', '', $part);
            $part = preg_replace('/\h+/u', ' ', $part);
            $part = preg_replace('/ +([,.;:!?»\)\]])/u', '$1', $part);
            $part = preg_replace('/([«\(\[]) +/u', '$1', $part);
            return preg_replace('/([,;:])(?: *\1)+/u', '$1', $part);
        }));
    }

    /** Split once, so protected tokens are never reinterpreted as generated prose. */
    private static function prose(string $text, array $allowed, callable $map): string {
        $tokens = ['(?i:https?)://[^\s<>]+', '(?<![\p{L}\p{N}_])[@/][\p{L}\p{N}_.\-]+'];
        $allowed = array_values(array_unique(array_filter($allowed, static fn($s) => is_string($s) && $s !== '')));
        usort($allowed, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($allowed as $part) $tokens[] = '(?<![' . self::LETTERS . '])' . preg_quote($part, '~') . '(?![' . self::LETTERS . '])';
        $parts = preg_split('~(' . implode('|', $tokens) . ')~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) if ($index % 2 === 0) $part = $map($part);
        return implode('', $parts);
    }
}
