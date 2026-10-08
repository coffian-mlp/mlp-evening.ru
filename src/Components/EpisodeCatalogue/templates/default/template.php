<?php
$esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$value = static fn($v) => $v === null ? '—' : (string)$v;
$data = $arResult['catalogue'];
$admin = $arResult['admin'];
$quota = $data['viewer']['quota'] ?? null;
$quotaValid = is_array($quota) && ($quota['limit'] ?? null) === 3 && is_int($quota['used'] ?? null) && $quota['used'] >= 0 && ($quota['remaining'] ?? null) === max(0, 3 - $quota['used']) && ($quota['timezone'] ?? '') === 'Europe/Kaliningrad'
    && is_string($quota['day'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $quota['day'])
    && is_string($quota['observed_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $quota['observed_at'])
    && is_string($quota['resets_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $quota['resets_at']);
if ($quotaValid) {
    $observed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $quota['observed_at'] ?? '', new \DateTimeZone('UTC'));
    $reset = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $quota['resets_at'] ?? '', new \DateTimeZone('UTC'));
    $local = $observed ? $observed->setTimezone(new \DateTimeZone('Europe/Kaliningrad')) : null;
    $quotaValid = $observed && $reset && $observed->format('Y-m-d\TH:i:s\Z') === $quota['observed_at'] && $reset->format('Y-m-d\TH:i:s\Z') === $quota['resets_at'] && $local->format('Y-m-d') === ($quota['day'] ?? '') && $local->modify('tomorrow')->setTime(0, 0)->getTimestamp() === $reset->getTimestamp() && $reset->getTimestamp() > time();
}
$exhausted = $quotaValid && $quota['remaining'] === 0;

$states = ['stale' => 'устарело', 'low_votes' => 'мало оценок', 'unknown' => 'данные отсутствуют'];
?>
<section class="episode-catalogue" aria-label="Каталог эпизодов">
    <h<?= $admin ? '3' : '1' ?>><?= $admin ? 'Полный список эпизодов' : 'Эпизоды' ?></h<?= $admin ? '3' : '1' ?>>
    <?php if ($data === null): ?>
        <p role="alert">Каталог временно недоступен. <a href="">Обновить страницу</a></p>
    <?php else: ?>
    <div class="episode-catalogue-filters">
        <label>Поиск <input type="search" data-catalogue-search placeholder="Код или название" autocomplete="off"></label>
        <label>Сезон <select class="no-custom" data-catalogue-season><option value="">Все сезоны</option>
            <?php $seasons = array_unique(array_filter(array_column($data['rows'], 'season'), static fn($s) => $s !== null)); sort($seasons);
            foreach ($seasons as $season): ?><option value="<?= (int)$season ?>">Сезон <?= (int)$season ?></option><?php endforeach; ?>
            <option value="special">Спецвыпуски</option></select></label>
        <label>Сортировка <select class="no-custom" data-catalogue-sort>
            <option value="code">Серия</option><option value="title">Название</option><option value="views">Просмотры</option><option value="wishes">Желания</option><option value="score">IMDb</option><option value="sd">Разброс оценок σ</option>
        </select></label>
        <button type="button" data-catalogue-direction aria-label="Изменить направление сортировки">По возрастанию</button>
        <button type="button" data-catalogue-reset>Сбросить</button>
    </div>
    <p class="episode-catalogue-note">До <?= (int)$data['limits']['daily'] ?> разных пожеланий в день; повторное желание той же серии — через 7 дней. Отмена не возвращает лимит.</p>
    <p class="episode-catalogue-note" data-catalogue-rating-help>Разброс оценок σ показывает, насколько различаются оценки зрителей на IMDb. Чем выше значение, тем больше разброс; это не показатель спорности эпизода в фандоме.</p>
    <details class="episode-catalogue-about"><summary>О пожеланиях и оценках</summary><p class="episode-catalogue-note">Желания: действующие пожелания участников и прежние голоса. IMDb — опубликованная оценка; σ — стандартное отклонение голосов, не оценка качества.</p>
    <p class="episode-catalogue-observation">Данные IMDb собраны (UTC): <?= $esc($data['ratings']['observation_min'] ?? 'дата неизвестна') ?><?= !empty($data['ratings']['observation_max']) && $data['ratings']['observation_max'] !== $data['ratings']['observation_min'] ? ' — ' . $esc($data['ratings']['observation_max']) : '' ?>. Устаревшие значения не являются актуальным рейтингом.</p></details>
    <p data-catalogue-quota role="status"><?= $data['viewer']['authenticated'] ? ($exhausted ? 'Дневной лимит исчерпан; отмена доступна.' : ($quotaValid ? 'Осталось сегодня: ' . (int)$quota['remaining'] . ' из 3.' : 'Остаток дневного лимита неизвестен; действие проверит сервер.')) : '' ?></p>
    <p data-catalogue-count>Показано <?= count($data['rows']) ?> эпизодов</p>
    <p class="episode-catalogue-feedback" role="status" aria-live="polite" data-catalogue-feedback tabindex="-1"></p>
    <div class="episode-catalogue-scroll" tabindex="0" aria-label="Таблица эпизодов, доступна горизонтальная прокрутка">
    <table><thead><tr>
        <?php foreach (['code'=>'Серия','title'=>'Название','views'=>'Просмотры','wishes'=>'Желания','score'=>'IMDb','sd'=>'Разброс оценок σ'] as $key=>$label): ?><th scope="col" data-catalogue-column="<?= $key ?>"><button type="button" data-catalogue-sort-key="<?= $key ?>"><?= $label ?></button></th><?php endforeach; ?>
        <th scope="col">Моё желание</th><?php if ($admin): ?><th scope="col">ID</th><th scope="col">TWOPART_ID</th><th scope="col">LENGTH</th><?php endif; ?>
    </tr></thead><tbody>
    <?php foreach ($data['rows'] as $row): $rating=$row['rating']; ?>
        <tr id="episode-<?= (int)$row['id'] ?>" data-episode-id="<?= (int)$row['id'] ?>">
            <td><?= $esc($row['code'] ?? 'Спецвыпуск') ?></td>
            <td><details><summary><?= $esc($row['title']) ?></summary><div class="episode-catalogue-details">
                <p>Количество оценок: <?= $esc($value($rating['votes'])) ?>. Наблюдение (UTC): <?= $esc($rating['observed_at'] ?? 'дата неизвестна') ?>.</p>
                <?php if ($rating['imdb_url'] !== null): ?><a href="<?= $esc($rating['imdb_url']) ?>" target="_blank" rel="noopener noreferrer">Страница IMDb</a><?php endif; ?>
                <?php if ($row['related'] !== null): ?><p><a href="#episode-<?= (int)$row['related']['id'] ?>" data-catalogue-related="<?= (int)$row['related']['id'] ?>">Связанная часть: <?= $esc($row['related']['code'] ?? $row['related']['title']) ?></a></p><?php endif; ?>
                <?php if ($rating['histogram'] === null): ?><p>Распределение голосов отсутствует.</p><?php else: ?><ol class="episode-catalogue-histogram" aria-label="Распределение голосов от 1 до 10">
                    <?php foreach ($rating['histogram'] as $i=>$n): ?><li><span><?= $i+1 ?>: <?= (int)$n ?></span><meter min="0" max="<?= max(1,(int)$rating['votes']) ?>" value="<?= (int)$n ?>" aria-label="Оценка <?= $i+1 ?>: <?= (int)$n ?> голосов"></meter></li><?php endforeach; ?>
                </ol><?php endif; ?>
            </div></details></td>
            <td><?= (int)$row['views'] ?></td><td><?= (int)$row['wishes'] ?></td>
            <td><?= $esc($value($rating['score'])) ?><?php if (isset($states[$rating['status']])): ?><small><?= $states[$rating['status']] ?></small><?php endif; ?></td><td><?= $esc($rating['sd'] === null ? '—' : round($rating['sd'], 3)) ?></td>
            <td><?php if (!$data['viewer']['authenticated']): ?><a href="/login.php?redirect=%2Fepisodes.php" data-catalogue-login>Войти, чтобы пожелать</a><?php else: ?><button type="button" data-catalogue-action="<?= (int)$row['id'] ?>"<?= $exhausted && !$row['own_active'] ? ' disabled title="Дневной лимит исчерпан. Отмена своего желания доступна." data-quota-disabled="true"' : '' ?>><?= $row['own_active'] ? 'Отменить желание' : ($exhausted ? 'Лимит на сегодня' : 'Хочу посмотреть') ?></button><?php endif; ?></td>
            <?php if ($admin): ?><td><?= (int)$row['id'] ?></td><td><?= $esc($value($row['admin']['two_part_id'])) ?></td><td><?= (int)$row['admin']['length'] ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p data-catalogue-empty hidden>Эпизоды не найдены. Измени поиск или сбрось фильтры.</p>
    <script type="application/json" class="episode-catalogue-data"><?= json_encode(['catalogue'=>$data,'admin'=>$admin], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <?php endif; ?>
</section>
