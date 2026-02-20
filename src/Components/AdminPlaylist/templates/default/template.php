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
            $confirmMsg = "Сгенерировать новый плейлист? Старый будет потерян.";
            if (!$arResult['meta']['is_old']) {
                $confirmMsg .= "\n\nВНИМАНИЕ: Текущий плейлист еще свежий (менее 7 дней)!";
            }
        ?>
        <button type="submit" onclick="return confirm('<?= $confirmMsg ?>')" class="btn-warning">🎲 Пересоздать плейлист</button>
    </form>
</div>

<div class="card">
    <h3 class="dashboard-title">✨ Случайная подборка на неделю</h3>
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
            <input type="hidden" name="ids" value="<?= htmlspecialchars($arResult['ids_string']) ?>">
            <button type="submit" class="btn-primary" onclick="return confirm('Отметить текущий плейлист как просмотренный?\n\nБудет сгенерирован НОВЫЙ плейлист, а текущий исчезнет.')">✅ Отметить просмотренным и обновить</button>
        </form>
    </div>
</div>
