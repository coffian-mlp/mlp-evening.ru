<?php
/**
 * @var array $arResult
 * Меню в шапке (MLP-259): горизонтальные ссылки (header-набор) — общий партиал
 * nav.php (MLP-266); дети — дропдауном. На мобиле (<768px, CSS) горизонталь
 * прячется — показывается сенобургер с burger-набором (общий партиал panel.php).
 */
$path = $arResult['current_path'];

$navItems = $arResult['items'];
$navClass = '';
require dirname(__DIR__, 2) . '/nav.php';
?>
<?php if (!empty($arResult['burger_items'])): ?>
<!-- Мобильная подача той же шапки (показывается CSS-ом <768px) -->
<div class="site-header-burger">
    <?php
        $panelItems = $arResult['burger_items'];
        require dirname(__DIR__, 2) . '/panel.php';
    ?>
</div>
<?php endif; ?>
