<?php $escape = static fn($text) => htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
<section class="evening-archive" aria-labelledby="archive-title">
    <header><p class="archive-eyebrow">MLP Evening</p><h1 id="archive-title">Архив вечерков</h1>
        <p>Когда собирались, что запланировали и что посмотрели.</p>
        <p class="archive-note">Просмотр засчитывается автоматически в конце вечерка. Если показ прервался, организатор может исправить список. Сохраняем историю с момента включения учёта.</p>
    </header>
    <?php if (!$arResult['items']): ?><p class="archive-empty">Здесь пока нет завершённых вечерков. После первого показа появятся программа и отметки просмотра.</p><?php endif; ?>
    <div class="archive-list">
    <?php foreach ($arResult['items'] as $item): ?>
        <article class="archive-card">
            <time datetime="<?= $escape($item['datetime']) ?>"><?= $escape($item['date']) ?> МСК</time>
            <h2><?= $escape($item['title']) ?></h2>
            <p class="archive-note"><?= !$item['known'] ? 'Отметки просмотра пока нет' : 'Засчитано просмотренным: '.$item['watched_count'].' из '.count($item['episodes']) ?><?= $item['corrected'] ? ' · Список исправлен организатором' : '' ?></p>
            <ol class="archive-program">
            <?php foreach ($item['episodes'] as $episode): ?>
                <li data-watched="<?= $episode['watched'] === null ? 'unknown' : ($episode['watched'] ? 'yes' : 'no') ?>"><span><?= $escape($episode['title']) ?></span><span class="archive-badge"><?= $episode['watched'] === null ? 'Нет отметки' : ($episode['watched'] ? 'Посмотрели' : 'Не посмотрели') ?></span></li>
            <?php endforeach; ?>
            </ol>
        </article>
    <?php endforeach; ?>
    </div>
    <nav class="archive-pages" aria-label="Страницы архива">
        <?php if ($arResult['page'] > 1): ?><a href="?page=<?= $arResult['page'] - 1 ?>">← Новее</a><?php endif; ?>
        <?php if ($arResult['has_next']): ?><a href="?page=<?= $arResult['page'] + 1 ?>">Раньше →</a><?php endif; ?>
    </nav>
</section>
