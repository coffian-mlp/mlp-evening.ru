<?php
use Components\SiteMenu\SiteMenuComponent;
/**
 * Партиал (MLP-259): кнопка-сенобургер + панель. Общий для шаблонов
 * 'burger' (главная) и 'header' (мобильная подача шапки) — правило промоции.
 * Ожидает: array $panelItems, string $path.
 */
?>
<?php
// MLP-290: пункт из объединённого набора, не входящий в burger-набор, показывается
// только на мобиле (>768px горизонталь шапки и так его рендерит).
$onlyMobile = static fn(array $i): string => empty($i['show_in_burger']) ? ' menu-only-mobile' : '';
?>
<div class="site-burger">
    <button type="button" class="site-burger-btn" aria-label="Меню" aria-expanded="false">
        <i></i><i></i><i></i>
    </button>
    <nav class="site-burger-panel" hidden>
        <?php foreach ($panelItems as $item): ?>
            <?php if ($item['url'] === null || $item['url'] === ''): ?>
                <div class="site-burger-group<?= $onlyMobile($item) ?>">
                    <button type="button" class="site-menu-item site-burger-parent" aria-expanded="false">
                        <?= htmlspecialchars($item['title']) ?> <span class="site-menu-caret">▾</span>
                    </button>
                    <div class="site-burger-children" hidden>
                        <?php foreach ($item['children'] as $child): ?>
                            <a class="site-menu-item site-menu-child <?= SiteMenuComponent::isActive($child, $path) ? 'active' : '' ?><?= $child['is_external'] ? ' external' : '' ?><?= $onlyMobile($child) ?>"
                               <?= SiteMenuComponent::linkAttrs($child) ?>>
                                <?= htmlspecialchars($child['title']) ?><?= $child['is_external'] ? ' <span class="ext-mark">↗</span>' : '' ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a class="site-menu-item <?= SiteMenuComponent::isActive($item, $path) ? 'active' : '' ?><?= $item['is_external'] ? ' external' : '' ?><?= $onlyMobile($item) ?>"
                   <?= SiteMenuComponent::linkAttrs($item) ?>>
                    <?= htmlspecialchars($item['title']) ?><?= $item['is_external'] ? ' <span class="ext-mark">↗</span>' : '' ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</div>
