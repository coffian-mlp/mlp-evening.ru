<?php
/**
 * @var array $arResult
 */
?>
<div class="video-stage">
<?php if($arResult['self_hosted']): ?>
<div class="video-content self-hosted-player" data-self-hosted-url="<?= htmlspecialchars($arResult['hls_url'],ENT_QUOTES,'UTF-8') ?>">
    <video controls playsinline preload="none" aria-label="Прямой эфир"></video>
    <div class="self-hosted-state"><p role="status">Подключаемся к эфиру…</p><button type="button" data-stream-retry>Подключиться ещё раз</button></div>
    <noscript>Для просмотра эфира нужен JavaScript.</noscript>
</div>
<?php else: ?>
<div class="video-content" data-stream-url="<?= htmlspecialchars($arResult['stream_url'], ENT_QUOTES, 'UTF-8') ?>"<?php if ($arResult['twitch'] !== null): ?> data-twitch-options="<?= htmlspecialchars(json_encode($arResult['twitch'], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
    <div class="player-privacy-placeholder"><p>Сторонний плеер пока отключён</p><small>При загрузке провайдер получит IP и сведения браузера и может использовать свои cookie.</small><button type="button" class="btn-primary" data-privacy-media>Разрешить плеер и загрузить</button><button type="button" class="privacy-secondary" data-privacy-open>Настроить разрешения</button><noscript>Для управления разрешениями и плеером нужен JavaScript.</noscript></div>
</div>
<?php endif; ?>
<div class="stream-emotions" aria-hidden="true"></div>
<?php $app->includeComponent('StreamReactions','default',['player_controls'=>true]); ?>
</div>
