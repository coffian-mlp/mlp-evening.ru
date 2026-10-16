<?php
/**
 * @var array $arResult
 */
?>
<div class="video-content" data-stream-url="<?= htmlspecialchars($arResult['stream_url'], ENT_QUOTES, 'UTF-8') ?>"<?php if ($arResult['twitch'] !== null): ?> data-twitch-options="<?= htmlspecialchars(json_encode($arResult['twitch'], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
    <div class="player-privacy-placeholder"><p>Сторонний плеер пока отключён</p><small>При загрузке провайдер получит IP и сведения браузера и может использовать свои cookie.</small><button type="button" class="btn-primary" data-privacy-media>Разрешить плеер и загрузить</button><button type="button" class="privacy-secondary" data-privacy-open>Настроить разрешения</button><noscript>Для управления разрешениями и плеером нужен JavaScript.</noscript></div>
</div>
