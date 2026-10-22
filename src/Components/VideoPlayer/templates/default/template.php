<?php
/**
 * @var array $arResult
 */
?>
<div class="video-stage">
<div class="video-content" data-stream-url="<?= htmlspecialchars($arResult['stream_url'], ENT_QUOTES, 'UTF-8') ?>"<?php if ($arResult['twitch'] !== null): ?> data-twitch-options="<?= htmlspecialchars(json_encode($arResult['twitch'], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
    <div class="player-privacy-placeholder"><p>Сторонний плеер пока отключён</p><small>При загрузке провайдер получит IP и сведения браузера и может использовать свои cookie.</small><button type="button" class="btn-primary" data-privacy-media>Разрешить плеер и загрузить</button><button type="button" class="privacy-secondary" data-privacy-open>Настроить разрешения</button><noscript>Для управления разрешениями и плеером нужен JavaScript.</noscript></div>
</div>
<div class="stream-emotions" aria-hidden="true"></div>
<div class="stream-reaction-tools">
    <button type="button" class="stream-reaction-toggle" aria-expanded="false" aria-label="Эмоции к эфиру" title="Эмоции к эфиру">☺</button>
    <button type="button" class="stream-fullscreen" aria-label="На весь экран с эмоциями" title="На весь экран с эмоциями">⛶</button>
    <section class="stream-reaction-panel" hidden aria-label="Эмоции к эфиру">
        <label class="stream-emotions-choice"><input type="checkbox" class="stream-emotions-enabled" checked> Показывать эмоции</label>
        <div class="stream-reaction-grid">
        <?php foreach (\Domain\StreamReactions::CATALOG as $key=>$reaction): ?>
            <button type="button" data-stream-reaction="<?= htmlspecialchars($key,ENT_QUOTES,'UTF-8') ?>" aria-label="<?= htmlspecialchars($reaction[1],ENT_QUOTES,'UTF-8') ?>" title="<?= htmlspecialchars($reaction[1],ENT_QUOTES,'UTF-8') ?>"><?= $reaction[0] ?></button>
        <?php endforeach; ?>
        </div>
        <small class="stream-reaction-hint" role="status">Одна эмоция раз в 3 секунды</small>
    </section>
</div>
</div>
