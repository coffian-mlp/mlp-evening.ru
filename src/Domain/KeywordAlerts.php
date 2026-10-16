<?php
namespace Domain;

/** Validated personal keyword notification preferences stored in user_options. */
final class KeywordAlerts {
    public static function normalize(string $value): string {
        if (strlen($value) > 4096) throw new \InvalidArgumentException('Список слов слишком длинный.');
        try { $data = json_decode($value, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('Не удалось прочитать настройки уведомлений.'); }
        if (!is_array($data) || !is_bool($data['enabled'] ?? null) || !is_array($data['words'] ?? null)
            || !array_is_list($data['words']) || count($data['words']) > 10) {
            throw new \InvalidArgumentException('Можно добавить до 10 слов или фраз.');
        }
        $words = []; $seen = [];
        foreach ($data['words'] as $word) {
            if (!is_string($word)) throw new \InvalidArgumentException('Каждое слово или фраза должны быть текстом.');
            $word = trim(preg_replace('/\s+/u', ' ', $word));
            if ($word === '') continue;
            if (mb_strlen($word) > 50 || preg_match('/[\x00-\x1F\x7F]/u', $word)) {
                throw new \InvalidArgumentException('В слове или фразе должно быть не больше 50 символов.');
            }
            $key = str_replace('ё', 'е', mb_strtolower($word));
            if (!isset($seen[$key])) { $words[] = $word; $seen[$key] = true; }
        }
        if ($data['enabled'] && !$words) throw new \InvalidArgumentException('Добавь хотя бы одно слово или фразу.');
        return json_encode(['enabled'=>$data['enabled'], 'words'=>$words], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
