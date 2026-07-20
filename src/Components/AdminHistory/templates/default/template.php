<?php
/**
 * @var array $arResult
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h3 class="dashboard-title">📜 История просмотров</h3>
        <form method="post" action="/api.php">
            <input type="hidden" name="action" value="clear_watching_log">
            <button type="submit" class="btn-danger" onclick="return confirm('Очистить визуальный лог просмотров?')">🗑️ Очистить список</button>
        </form>
    </div>
    
    <table class="dashboard-table" id="watch-history-table">
        <tr><th>Time ID</th><th>Ep ID</th><th>Title</th></tr>
        <?php foreach ($arResult['watchHistory'] as $row): ?>
            <tr>
                <td><?= $row['ID'] ?></td>
                <td><?= $row['EPNUM'] ?></td>
                <td><?= htmlspecialchars($row['TITLE']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
