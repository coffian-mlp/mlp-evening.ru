<?php $playerControls=$arResult['player_controls']; ?>
<div class="stream-reaction-tools<?= !empty($arResult['toolbar'])?' stream-reaction-tools--toolbar':'' ?>" data-stream-reactions="<?= $playerControls?'player':'chat' ?>">
    <button type="button" class="stream-reaction-toggle" aria-expanded="false" aria-label="Эмоции к эфиру" title="Эмоции к эфиру"><?php if ($playerControls): ?><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="13" r="8"/><path d="M7 14.5q3.5 4 7 0"/><path d="M7.5 10.5h.01m5.99 0h.01" stroke-width="2.5" stroke-linecap="round"/><path class="stream-reaction-spark" d="m19 1 1.1 3.4L23.5 5.5l-3.4 1.1L19 10l-1.1-3.4-3.4-1.1 3.4-1.1z"/></svg><span class="stream-reaction-label">Эмоции</span><?php else: ?>☺<?php endif; ?></button>
    <?php if ($playerControls): ?>
        <button type="button" class="stream-fullscreen" aria-label="На весь экран с эмоциями" title="На весь экран с эмоциями"><svg viewBox="0 0 24 24" aria-hidden="true"><path class="stream-fullscreen-enter" d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/><path class="stream-fullscreen-exit" d="M3 8h5V3m13 5h-5V3M8 21v-5H3m13 5v-5h5"/></svg></button>
    <?php endif; ?>
    <section class="stream-reaction-panel" hidden aria-label="Эмоции к эфиру">
        <?php if ($playerControls): ?>
            <label class="stream-emotions-choice"><input type="checkbox" class="stream-emotions-enabled" checked> Показывать эмоции</label>
        <?php else: ?>
            <p class="stream-reaction-description">Эмоции появятся поверх плеера у зрителей.</p>
        <?php endif; ?>
        <div class="stream-reaction-grid">
        <?php foreach (\Domain\StreamReactions::CATALOG as $key=>$reaction): ?>
            <button type="button" data-stream-reaction="<?= htmlspecialchars($key,ENT_QUOTES,'UTF-8') ?>" aria-label="<?= htmlspecialchars($reaction[1],ENT_QUOTES,'UTF-8') ?>" title="<?= htmlspecialchars($reaction[1],ENT_QUOTES,'UTF-8') ?>"><?= $reaction[0] ?></button>
        <?php endforeach; ?>
        </div>
        <small class="stream-reaction-hint" role="status">Одна эмоция раз в 3 секунды</small>
    </section>
</div>
