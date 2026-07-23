<?php
use Components\SiteMenu\SiteMenuComponent;
/**
 * Партиал горизонтального меню (MLP-266, AR6-7) — общий для шаблонов
 * header (шапка страниц) и stream (полоска главной), как panel.php у бургера.
 *
 * Ожидает в области видимости:
 *   @var array  $navItems пункты header-набора (родители с детьми — дропдауном)
 *   @var string $path     текущий путь (подсветка active)
 *   @var string $navClass дополнительный класс <nav> ('' | 'site-nav-stream')
 */
?>
<?php if (!empty($navItems)): ?>
<nav class="site-nav<?= $navClass !== '' ? ' ' . htmlspecialchars($navClass) : '' ?>">
    <?php foreach ($navItems as $item): ?>
        <?php if ($item['url'] === null || $item['url'] === ''): ?>
            <div class="site-nav-group">
                <button type="button" class="site-nav-link site-nav-parent" aria-expanded="false">
                    <?= htmlspecialchars($item['title']) ?> <span class="site-menu-caret">▾</span>
                </button>
                <div class="site-nav-dropdown" hidden>
                    <?php foreach ($item['children'] as $child): ?>
                        <a class="site-menu-item <?= SiteMenuComponent::isActive($child, $path) ? 'active' : '' ?><?= $child['is_external'] ? ' external' : '' ?>"
                           <?= SiteMenuComponent::linkAttrs($child) ?>>
                            <?= htmlspecialchars($child['title']) ?><?= $child['is_external'] ? ' <span class="ext-mark">↗</span>' : '' ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <a class="site-nav-link <?= SiteMenuComponent::isActive($item, $path) ? 'active' : '' ?><?= $item['is_external'] ? ' external' : '' ?>"
               <?= SiteMenuComponent::linkAttrs($item) ?>>
                <?= htmlspecialchars($item['title']) ?><?= $item['is_external'] ? ' <span class="ext-mark">↗</span>' : '' ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
