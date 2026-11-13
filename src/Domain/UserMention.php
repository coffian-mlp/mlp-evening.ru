<?php
namespace Domain;

use Infra\Database;

/** Complete known identities, longest alias first; unknown or ambiguous names are not guessed. */
final class UserMention {
    public static function normalize(string $name): string {
        return trim(preg_replace('/[\p{Cf}]/u', '', $name));
    }

    /** A nickname is an address only when no other identity owns that alias. */
    public static function addressName(array $user, array $directory): string {
        $nick = self::normalize((string)($user['nickname'] ?? ''));
        if ($nick !== '' && !preg_match('/[\r\n<>@]/u', $nick)) {
            $key = mb_strtolower($nick);
            foreach ($directory as $other) {
                if ((int)$other['id'] === (int)$user['id']) continue;
                foreach (['login', 'nickname'] as $field) {
                    if (mb_strtolower(self::normalize((string)($other[$field] ?? ''))) === $key) return (string)$user['login'];
                }
            }
            return $nick;
        }
        return (string)$user['login'];
    }

    /** Resolve queued or saved names by id at use time; guests keep their name. */
    public static function currentAddressName(int $userId, string $fallback = 'Гость'): string {
        if ($userId <= 0) return $fallback;
        $directory = (new UserManager())->mentionDirectory();
        foreach ($directory as $user) {
            if ((int)$user['id'] === $userId) return self::addressName($user, $directory);
        }
        return $fallback;
    }

    /** Canonicalize known mentions in bot output, without changing human messages. */
    public static function addressMentions(string $text, array $directory): string {
        // Code and link destinations are literal data, not addresses to participants.
        $parts = preg_split('~(```[\s\S]*?(?:```|$)|`[^`\n]*`|!?\[[^\]\n]*\]\([^\n]*?\)|<[^>\n]*>|https?://[^\s<>]+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            if ($index % 2) continue;
            foreach (array_reverse(self::matches($part, $directory)) as $match) {
                $part = substr_replace($part, '@' . self::addressName($match['user'], $directory), $match['offset'], strlen($match['raw']));
            }
        }
        unset($part);
        return implode('', $parts);
    }

    private static ?array $rows = null;
    private static int $loadedAt = 0;

    public static function invalidateDirectory(): void { self::$rows = null; }

    public static function directory(): array {
        if (self::$rows === null || time() - self::$loadedAt >= 2) {
            self::$rows = (new UserManager())->mentionDirectory();
            self::$loadedAt = time();
        }
        return self::$rows;
    }

    public static function matches(string $text, array $users): array {
        $aliases = [];
        foreach ($users as $user) {
            foreach ([$user['login'] ?? '', $user['nickname'] ?? ''] as $name) {
                $name = self::normalize((string)$name);
                if ($name === '' || preg_match('/[\r\n<>@]/u', $name)) continue;
                $key = mb_strtolower($name);
                if (isset($aliases[$key]) && (int)$aliases[$key]['user']['id'] !== (int)$user['id']) $aliases[$key]['ambiguous'] = true;
                else $aliases[$key] ??= ['name'=>$name, 'user'=>$user, 'ambiguous'=>false];
            }
        }
        uasort($aliases, fn($a,$b)=>mb_strlen($b['name']) <=> mb_strlen($a['name']));
        $found = [];
        foreach ($aliases as $alias) {
            $letters = preg_split('//u', $alias['name'], -1, PREG_SPLIT_NO_EMPTY);
            $body = implode('[\p{Cf}]*', array_map(fn($letter)=>preg_quote($letter, '~'), $letters));
            $pattern = '~(?<![\p{L}\p{N}_@/])@[\p{Cf}]*'.$body.'[\p{Cf}]*(?![\p{L}\p{N}_-])~iu';
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$raw,$offset]) {
                $overlap = false;
                foreach ($found as $match) if ($offset < $match['offset'] + strlen($match['raw']) && $offset + strlen($raw) > $match['offset']) $overlap = true;
                if (!$overlap) $found[] = ['raw'=>$raw, 'offset'=>$offset, 'name'=>$alias['name'], 'user'=>$alias['user'], 'ambiguous'=>$alias['ambiguous']];
            }
        }
        usort($found, fn($a,$b)=>$a['offset'] <=> $b['offset']);
        return array_values(array_filter($found, fn($match)=>!$match['ambiguous']));
    }

    public static function render(string $html, array $users): string {
        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $protected = 0;
        foreach ($parts as &$part) {
            if (str_starts_with($part,'<')) {
                if (preg_match('~^<(a|code|pre)\b~i',$part)) $protected++;
                elseif (preg_match('~^</(a|code|pre)>~i',$part)) $protected=max(0,$protected-1);
                continue;
            }
            if ($protected || !str_contains($part,'@')) continue;
            // Decode for matching, then escape each original text segment exactly once.
            $raw = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $out = ''; $cursor = 0;
            foreach (self::matches($raw,$users) as $match) {
                $out .= htmlspecialchars(substr($raw,$cursor,$match['offset']-$cursor),ENT_QUOTES,'UTF-8');
                $out .= '<span class="md-mention" data-mention-user-id="'.(int)$match['user']['id'].'">'.htmlspecialchars('@'.$match['name'],ENT_QUOTES,'UTF-8').'</span>';
                $cursor = $match['offset']+strlen($match['raw']);
            }
            $part = $out.htmlspecialchars(substr($raw,$cursor),ENT_QUOTES,'UTF-8');
        }
        unset($part);
        return implode('',$parts);
    }
}
