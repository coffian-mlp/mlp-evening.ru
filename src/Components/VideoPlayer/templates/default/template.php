<?php
/**
 * @var array $arResult
 */
?>
<div class="video-stage">
<?php if($arResult['self_hosted']): ?>
<div class="video-content self-hosted-player" data-self-hosted-url="<?= htmlspecialchars($arResult['hls_url'],ENT_QUOTES,'UTF-8') ?>">
    <div class="self-hosted-media">
    <video playsinline preload="none" aria-label="Прямой эфир"></video>
    <div class="self-hosted-state">
        <img class="self-hosted-buffering" data-stream-buffering data-animated-src="/assets/img/player/lyra-buffering-v2.webp" data-static-src="/assets/img/player/lyra-buffering-still-v2.webp" width="420" height="280" alt="" aria-hidden="true" decoding="async" hidden>
        <p role="status">Ожидаем эфир. Видео включится автоматически.</p><button type="button" data-stream-retry>Проверить сейчас</button>
    </div>
    <noscript>Для просмотра эфира нужен JavaScript.</noscript>
    </div>
    <div class="self-hosted-controls" role="group" aria-label="Управление эфиром">
        <button type="button" data-stream-play aria-label="Смотреть прямой эфир" title="Смотреть прямой эфир" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path data-stream-play-icon d="M8 5v14l11-7z"/></svg></button>
        <button type="button" data-stream-mute aria-label="Включить звук" title="Включить звук"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9v6h4l5 4V5L7 9z"/><path data-stream-sound-icon d="m16 9 6 6m0-6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
        <input type="range" data-stream-volume min="0" max="1" step="0.01" value="1" aria-label="Громкость">
        <?php $app->includeComponent('StreamReactions','default',['player_controls'=>true,'toolbar'=>true]); ?>
    </div>
</div>
<?php else: ?>
<div class="video-content" data-stream-url="<?= htmlspecialchars($arResult['stream_url'], ENT_QUOTES, 'UTF-8') ?>"<?php if ($arResult['twitch'] !== null): ?> data-twitch-options="<?= htmlspecialchars(json_encode($arResult['twitch'], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
    <div class="player-privacy-placeholder"><p>Сторонний плеер пока отключён</p><small>При загрузке провайдер получит IP и сведения браузера и может использовать свои cookie.</small><button type="button" class="btn-primary" data-privacy-media>Разрешить плеер и загрузить</button><button type="button" class="privacy-secondary" data-privacy-open>Настроить разрешения</button><noscript>Для управления разрешениями и плеером нужен JavaScript.</noscript></div>
</div>
<?php endif; ?>
<div class="stream-emotions" aria-hidden="true"></div>
<?php if(!$arResult['self_hosted']) $app->includeComponent('StreamReactions','default',['player_controls'=>true]); ?>
</div>
