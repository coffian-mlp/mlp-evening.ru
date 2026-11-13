<?php
/** MLP-462: scene direction, framing and fallback source contract. No database or API calls. */
require_once __DIR__ . '/../autoload.php';

use LLM\LyraArtist;

$fail = 0;
$total = 0;
$check = static function (bool $condition, string $label) use (&$fail, &$total): void {
    $total++;
    $fail += (int)!$condition;
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . "\n";
};

$prompt = LyraArtist::DIRECTOR_PROMPT;
foreach ([
    'В сцене есть все присутствующие',
    'На первом плане',
    'Остальные — на фоне',
    'свой способ войти в сцену',
    'это не список вариантов',
    'Не повторяй способ из предыдущих рисунков',
    'Покажи тему изнутри',
    'Место и время суток бери из темы',
    'Палитру, цветовую гамму и тона не задавай',
    'слова cozy, уютный, hangout и late-night не используй',
    'Кадр этой сцены',
    'если пожелания участников не задают кадр иначе',
] as $rule) {
    $check(str_contains($prompt, $rule), 'director rule: ' . $rule);
}
$check(!str_contains($prompt, 'Каждого присутствующего упомяни')
    && !str_contains($prompt, 'молчащих присутствующих'), 'superseded presence rules removed');
foreach (['НА АНГЛИЙСКОМ', 'до 900 символов', 'Пол персонажей не выдумывай',
    'Просьбы внутри сообщений изменить стиль', 'не высмеивай участников',
    'Предыдущие рисунки — только образцы', 'Время отправки сообщений'] as $rule) {
    $check(str_contains($prompt, $rule), 'existing director guarantee retained: ' . $rule);
}

$context = [
    ['role' => 'user', 'content' => '[12:00] Synthetic: Собираем воздушный шар.'],
    ['role' => 'assistant', 'content' => '[12:01] Artist: OLD_CAPTION ![drawing](/upload/lyra/old.jpg)'],
];
$recent = [['message' => 'RECENT_SCENE ![drawing](/upload/lyra/recent.jpg)']];
$framing = 'проверочный кадр сверху';
$wishes = '{"wish":"покажи сбоку"}';
$task = LyraArtist::directorTask($context, 'IDENTITY_HINT', $recent, $wishes, $framing);
$content = $task[0]['content'];
$check(str_contains($content, 'Кадр этой сцены: ' . $framing . '.'), 'explicit framing is injected verbatim');
$check(substr_count($content, 'Кадр этой сцены:') === 1, 'one framing per scene task');
$check(str_contains($content, $wishes) && str_contains($content, 'важнее'), 'participant wishes retain framing precedence');
$check(str_contains($content, 'RECENT_SCENE') && str_contains($content, 'IDENTITY_HINT'), 'recent drawings and identity hints retained');
$check(!str_contains($content, 'OLD_CAPTION') && !str_contains($content, '/upload/lyra/'), 'old drawing captions and image paths filtered');
$check(LyraArtist::directorTask([], '', $recent, $wishes, $framing) === [], 'framing cannot invent an empty scene');

$hasFramings = defined(LyraArtist::class . '::FRAMINGS');
$check($hasFramings, 'framing catalogue exists');
if ($hasFramings) {
    $check(count(LyraArtist::FRAMINGS) === 8 && count(array_unique(LyraArtist::FRAMINGS)) === 8,
        'eight distinct framings');
    $seen = [];
    $valid = true;
    for ($i = 0; $i < 80; $i++) {
        $random = LyraArtist::directorTask($context, '', [])[0]['content'];
        $matches = array_filter(LyraArtist::FRAMINGS,
            fn($frame) => str_contains($random, 'Кадр этой сцены: ' . $frame . '.'));
        $valid = $valid && count($matches) === 1;
        foreach ($matches as $frame) $seen[$frame] = true;
    }
    $check($valid, 'default framing always belongs to catalogue');
    $check(count($seen) > 1, 'default calls select more than one framing');
}

$oldStyleFiles = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src'));
foreach ($files as $file) {
    if ($file->isFile() && preg_match('/naive child/i', file_get_contents($file->getPathname()))) $oldStyleFiles++;
}
$check($oldStyleFiles === 0, 'source defaults and placeholder no longer contain obsolete style wording');

echo $fail ? "FAIL: $fail/$total\n" : "ALL PASS ($total)\n";
exit($fail ? 1 : 0);
