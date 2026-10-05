<?php
/**
 * @var array $arResult
 */
?>
<!-- Информация о плейлисте -->
<div class="playlist-info">
    <div>
        <?php if ($arResult['meta']): ?>
            <span class="playlist-date">📅 Сгенерирован: <strong><?= $arResult['meta']['updated_at'] ?></strong></span>
            <?php if ($arResult['meta']['is_old']): ?>
                <span class="status-badge old">⚠️ Устарел (> 7 дней)</span>
            <?php else: ?>
                <span class="status-badge fresh">✅ Актуален</span>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <form method="post" action="/api.php">
        <input type="hidden" name="action" value="regenerate_playlist">
        <?php 
            $confirmMsg = "Опубликовать новый плейлист? Предыдущий снимок останется в истории.";
            if (empty($arResult['meta']['is_old'])) {
                $confirmMsg .= "\n\nВНИМАНИЕ: Текущий плейлист еще свежий (менее 7 дней)!";
            }
        ?>
        <button type="submit" onclick="return confirm(<?= htmlspecialchars(json_encode($confirmMsg), ENT_QUOTES) ?>)" class="btn-warning">🎲 Пересоздать плейлист</button>
    </form>
</div>

<div class="card">
    <h3 class="dashboard-title">✨ Случайная подборка на неделю</h3>
    <?php if (empty($arResult['meta']['snapshot_id'])): ?><p>Существующая подборка ещё не перенесена в версионную историю. При публикации нового плейлиста она будет сохранена отдельным снимком.</p><?php endif; ?>
    <ol class="playlist-list">
    <?php foreach ($arResult['playlist'] as $episode): ?>
        <li>
            <strong><?= htmlspecialchars(implode(' / ', $episode['titles'])) ?></strong>
            <span class="meta">(ID: <?= implode('/', $episode['ids']) ?>)</span>
        </li>
    <?php endforeach; ?>
    </ol>

    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
        <form method="post" action="/api.php" style="display:inline;">
            <input type="hidden" name="action" value="mark_watched">
            <input type="hidden" name="snapshot_id" value="<?= (int)($arResult['meta']['snapshot_id'] ?? 0) ?>">
            <input type="hidden" name="ids" value="<?= htmlspecialchars($arResult['ids_string']) ?>">
            <button type="submit" class="btn-primary" <?= empty($arResult['meta']['snapshot_id']) ? 'disabled' : '' ?> onclick="return confirm('Отметить текущий плейлист как просмотренный?\n\nБудет опубликован новый плейлист; история текущего сохранится.')">✅ Отметить просмотренным и обновить</button>
        </form>
    </div>
</div>

<div class="card">
    <h3>Корректировка просмотра</h3>
    <p>Выбери сохранённый снимок; отметь истории, которые действительно были показаны.</p>
    <?php foreach ($arResult['recent_snapshots'] as $saved): ?>
        <a href="?snapshot_id=<?= (int)$saved['id'] ?>#tab-episodes">№<?= (int)$saved['id'] ?> (<?= htmlspecialchars($saved['created_at']) ?>)</a>
    <?php endforeach; ?>
    <?php $snapshot = $arResult['correction_snapshot']; if ($snapshot && $snapshot['completed_at'] && $arResult['selected_completion']): $completion = $arResult['selected_completion']; $shown = json_decode($completion['shown_json'] ?? '[]', true) ?: []; ?>
    <?php foreach ($arResult['completions'] as $past): ?>
        <a href="?snapshot_id=<?= (int)$snapshot['id'] ?>&amp;completion_key=<?= urlencode($past['completion_key']) ?>#tab-episodes"><?= htmlspecialchars($past['completed_at']) ?></a>
    <?php endforeach; ?>
    <form method="post" action="/api.php" data-playlist-correction>
        <input type="hidden" name="completion_key" value="<?= htmlspecialchars($completion['completion_key'] ?? '') ?>">
        <input type="hidden" name="action" value="correct_playlist_completion">
        <input type="hidden" name="snapshot_id" value="<?= (int)$snapshot['id'] ?>">
        <input type="hidden" name="operation_key" value="<?= htmlspecialchars($arResult['correction_key']) ?>">
        <?php foreach ($snapshot['stories'] as $story): ?>
        <label style="display:block"><input type="checkbox" name="story_ids[]" value="<?= htmlspecialchars($story['story_id']) ?>" <?= in_array($story['story_id'], $shown, true) ? 'checked' : '' ?>> <?= htmlspecialchars(implode(' / ', $story['titles'])) ?></label>
        <?php endforeach; ?>
        <button type="submit">Сохранить корректировку</button>
    </form>
    <?php else: ?><p>Выбранный снимок ещё не завершён.</p><?php endif; ?>
</div>
