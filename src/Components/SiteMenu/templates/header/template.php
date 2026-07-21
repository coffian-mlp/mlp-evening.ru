<?php
use Components\SiteMenu\SiteMenuComponent;
/**
 * @var array $arResult
 * Меню в шапке (MLP-259): горизонтальные ссылки (header-набор), дети —
 * дропдауном. На мобиле (<768px, CSS) горизонталь прячется — показывается
 * сенобургер с burger-набором (общий партиал panel.php).
 */
$path = $arResult['current_path'];
?>
<?php if (!empty($arResult['items'])): ?>
<nav class="site-nav">
    <?php foreach ($arResult['items'] as $item): ?>
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

<?php if (!empty($arResult['burger_items'])): ?>
<!-- Мобильная подача той же шапки (показывается CSS-ом <768px) -->
<div class="site-header-burger">
    <?php
        $panelItems = $arResult['burger_items'];
        require dirname(__DIR__, 2) . '/panel.php';
    ?>
</div>
<?php endif; ?>
