<?php
use Components\SiteMenu\SiteMenuComponent;
/**
 * @var array $arResult
 * Полоска главной (MLP-260): сенобургер (burger-набор, всегда) + горизонтальные
 * пункты header-набора — справа от лого, в пустом месте полоски. Порядок
 * бургер/лого/горизонталь задаётся CSS-order в .video-container .header.
 * На мобиле горизонталь прячется глобальным правилом .site-nav (≤768px).
 */
$path = $arResult['current_path'];

if (!empty($arResult['items'])) {
    $panelItems = $arResult['items'];
    require dirname(__DIR__, 2) . '/panel.php';
}
?>
<?php if (!empty($arResult['nav_items'])): ?>
<nav class="site-nav site-nav-stream">
    <?php foreach ($arResult['nav_items'] as $item): ?>
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
