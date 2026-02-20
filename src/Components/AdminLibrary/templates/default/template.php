<?php
/**
 * @var array $arResult
 */
?>
<div class="card">
    <h3 class="dashboard-title">Полный список эпизодов</h3>
    
    <div class="search-bar">
        <input type='text' id='searchInput' placeholder='🔍 Поиск по названию...' class="search-input">
        <span class="search-hint">Нажмите на заголовок для сортировки</span>
    </div>

    <div style="overflow-x: auto;">
        <table id='fulltable' class="dashboard-table">
            <thead>
                <tr><th>ID</th><th>Title</th><th>Watched</th><th>Votes</th><th>2-Part</th><th>Len</th></tr>
            </thead>
            <tbody>
            <?php foreach ($arResult['allEpisodes'] as $row): ?>
                <tr>
                    <td><?= $row['ID'] ?></td>
                    <td><?= htmlspecialchars($row['TITLE']) ?></td>
                    <td><?= $row['TIMES_WATCHED'] ?></td>
                    <td><?= $row['WANNA_WATCH'] ?></td>
                    <td><?= $row['TWOPART_ID'] ?></td>
                    <td><?= $row['LENGTH'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="dashboard-title">Двусерийные эпизоды</h3>
    <table class="dashboard-table">
        <thead>
            <tr><th>ID</th><th>Title</th><th>Len</th></tr>
        </thead>
        <tbody>
        <?php foreach ($arResult['twoPartEpisodes'] as $row): ?>
            <tr>
                <td><?= $row['ID'] ?></td>
                <td><?= htmlspecialchars($row['TITLE']) ?></td>
                <td><?= $row['LENGTH'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
