<section class="card ux-statistics">
    <h2 class="dashboard-title">Статистика интерфейса</h2>
    <p>Только браузеры, разрешившие статистику. Посещение завершается после 30 минут без активности или при смене суток UTC. Это не число уникальных людей. Разбивки ниже показывают просмотры страниц.</p>
    <form action="/dashboard/#tab-statistics" method="get">
        <label>Период <select name="stats_days" class="form-input" onchange="this.form.submit()">
            <?php foreach ([7,30,90,365] as $days): ?><option value="<?= $days ?>" <?= $arResult['days']===$days?'selected':'' ?>><?= $days ?> дней</option><?php endforeach; ?>
        </select></label>
    </form>
    <div class="ux-counters"><div><strong><?= $arResult['visits'] ?></strong> посещений</div><div><strong><?= $arResult['views'] ?></strong> просмотров страниц</div><div><strong><?= $arResult['peak'] ?></strong> пик активных посещений</div></div>
    <p>Пик учитывает участвующие посещения с активностью за последние 3 минуты. Это не полный онлайн сайта.</p>
    <?php if (!$arResult['views']): ?><p class="ux-empty">Пока нет измерений за этот период. Они появятся после разрешения посетителей; старые сессии не импортируются.</p><?php endif; ?>
    <?php if ($arResult['series']): ?>
        <details open><summary>Посещаемость по дням (UTC)</summary><div class="ux-table-scroll"><table><thead><tr><th>Дата</th><th>Посещения</th><th>Страницы</th></tr></thead><tbody>
        <?php foreach (array_reverse($arResult['series']) as $row): ?><tr><td><?= htmlspecialchars($row['day']) ?></td><td><?= $row['visits'] ?></td><td><?= $row['views'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div></details>
    <?php endif; ?>
    <div class="ux-distributions">
        <?php foreach (\Domain\UxStatistics::DIMENSIONS as $dimension=>$title): $total=array_sum($arResult['groups'][$dimension]); ?>
        <details><summary><?= htmlspecialchars($title) ?></summary>
            <?php if (!$total): ?><p>Нет данных</p><?php else: ?>
            <div class="ux-table-scroll"><table><thead><tr><th>Значение</th><th>Страниц</th><th>Доля</th></tr></thead><tbody>
            <?php foreach ($arResult['groups'][$dimension] as $label=>$count): ?><tr><td><?= htmlspecialchars((string)$label) ?></td><td><?= $count ?></td><td><?= round(100*$count/$total,1) ?>%</td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </details>
        <?php endforeach; ?>
    </div>
    <p>Размеры округлены до 100 CSS px. DPR смешивает плотность экрана и увеличение страницы — это не точный масштаб браузера. Увеличение жестом доступно не везде. Технические ошибки — признаки сбоев на страницах, без текста сообщений и содержимого запросов.</p>
</section>
