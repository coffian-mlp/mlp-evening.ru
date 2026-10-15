<?php
/**
 * @var array $arResult
 */
?>
<div class="video-content"<?php if ($arResult['twitch'] !== null): ?> data-twitch-options="<?= htmlspecialchars(json_encode($arResult['twitch'], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>" data-stream-url="<?= htmlspecialchars($arResult['stream_url'], ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
    <?php if ($arResult['twitch'] !== null): ?>
    <noscript>
    <?php endif; ?>
    <iframe 
        src="<?= htmlspecialchars($arResult['stream_url']) ?>" 
        allowfullscreen 
        allow="autoplay">
    </iframe>
    <?php if ($arResult['twitch'] !== null): ?>
    </noscript>
    <?php endif; ?>
</div>
