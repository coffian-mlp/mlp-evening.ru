<?php
/**
 * @var array $arResult
 */
?>
<!-- MLP-256: вариант 'episode-tools' — ручное голосование и опасная зона (вкладка «Эпизоды») -->
<div class="card">
    <h3 class="dashboard-title">🗳️ Голосование (Ручной режим)</h3>
    <p>Если нужно добавить голос за конкретную серию вручную:</p>
    
    <form method="post" action="/api.php" style="margin-top: 15px;">
        <input type="hidden" name="action" value="vote">
        <label for="episode_id">ID Эпизода:</label>
        <input type="number" id="episode_id" name="episode_id" min="1" max="221" required placeholder="1-221" style="width: 100px;">
        <button type="submit" class="btn-primary">Добавить голос (+1 Wanna Watch)</button>
    </form>
</div>

<div class="card danger-zone">
    <h3 class="dashboard-title" style="color: #c0392b;">⚠️ Опасная зона</h3>
    <p>Глобальный сброс параметров. Будьте осторожны.</p>
    
    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
        <form method="post" action="/api.php">
            <input type="hidden" name="action" value="clear_votes">
            <button type="submit" class="btn-danger" onclick="return confirm('Точно сбросить все голоса (WANNA_WATCH)?')">🗑️ Сбросить голоса</button>
        </form>

        <form method="post" action="/api.php">
            <input type="hidden" name="action" value="reset_times_watched">
            <button type="submit" class="btn-danger" onclick="return confirm('Точно сбросить счетчики просмотров? Все серии снова станут непросмотренными!')">🔄 Сбросить просмотры</button>
        </form>
    </div>
</div>
