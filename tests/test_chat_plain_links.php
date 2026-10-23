<?php
require_once __DIR__ . '/../autoload.php';

$chat = (new ReflectionClass(Domain\ChatManager::class))->newInstanceWithoutConstructor();
$parse = new ReflectionMethod(Domain\ChatManager::class, 'parseMarkdown');
$failures = 0;
function checkLink(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) ++$failures;
}
$render = static fn(string $text): string => $parse->invoke($chat, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
foreach ([
    'https://example.test/image.png', 'http://example.test/image.gif',
    'https://www.youtube.com/watch?v=abcdefghijk', 'https://youtu.be/abcdefghijk',
    'https://rutube.ru/video/abcdef123/', 'https://example.test/video.mp4',
    'https://example.test/video.webm', 'https://example.test/a.png?x=1&y=2',
    'https://example.test/**bold**/||spoiler||.png',
    'https://example.test/проверка.png',
] as $url) {
    $escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $html = $render('<' . $url . '>');
    checkLink($html === '<a href="' . $escaped . '" target="_blank" rel="noopener noreferrer" class="chat-link">' . $escaped . '</a>', 'explicit plain link: ' . $url);
}
$html = $render('Смотри <https://example.test/one.png> и https://example.test/two.png');
checkLink(substr_count($html, '<a ') === 1 && substr_count($html, '<img ') === 1, 'only marked URL suppresses media');
$html = $render('<https://example.test/a.png> <https://example.test/b.mp4>');
checkLink(substr_count($html, '<a ') === 2 && !str_contains($html, '<img') && !str_contains($html, '<video'), 'multiple independent plain links');
$html = $render('> <https://example.test/a.png>');
checkLink(str_contains($html, '<blockquote') && str_contains($html, '<a ') && !str_contains($html, '<img'), 'plain link inside a quote');
foreach (['javascript:alert(1)', 'data:text/html,test', 'ftp://example.test/a.png', '//example.test/a.png'] as $url) {
    checkLink(!str_contains($render('<' . $url . '>'), '<a '), 'unsupported scheme remains text: ' . $url);
}
$html = $render('<https://example.test/a.png?x="onmouseover="alert(1)>');
checkLink(!str_contains($html, ' onmouseover=') && str_contains($html, '&quot;'), 'quotes in URL stay escaped');
$html = $render('<https://example.test/a.png><script>alert(1)</script>');
checkLink(!str_contains($html, '<script') && str_contains($html, '&lt;script&gt;'), 'surrounding HTML remains escaped');
$html = $render("\x1Aplain-link-0\x1A <https://example.test/a.png>");
checkLink(str_starts_with($html, "\x1Aplain-link-0\x1A ") && substr_count($html, '<a ') === 1, 'input cannot collide with protected link tokens');
foreach (['https://example.test/a.png' => '<img ', 'https://youtu.be/abcdefghijk' => '<iframe ', 'https://example.test/a.mp4' => '<video '] as $url => $tag) {
    checkLink(str_contains($render($url), $tag), 'existing automatic media remains: ' . $url);
}
echo $failures ? "FAIL: $failures\n" : "ALL PASS\n";
exit($failures ? 1 : 0);
