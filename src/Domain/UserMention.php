<?php
namespace Domain;

use Infra\Database;

/** Complete known identities, longest alias first; unknown or ambiguous names are not guessed. */
final class UserMention {
    public static function normalize(string $name): string {
        return trim(preg_replace('/[\p{Cf}]/u', '', $name));
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
