<?php $playerControls=$arResult['player_controls']; ?>
<div class="stream-reaction-tools" data-stream-reactions="<?= $playerControls?'player':'chat' ?>">
    <button type="button" class="stream-reaction-toggle" aria-expanded="false" aria-label="Эмоции к эфиру" title="Эмоции к эфиру">☺</button>
    <?php if ($playerControls): ?>
        <button type="button" class="stream-fullscreen" aria-label="На весь экран с эмоциями" title="На весь экран с эмоциями">⛶</button>
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
