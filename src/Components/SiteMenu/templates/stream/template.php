<?php
/**
 * @var array $arResult
 * Полоска главной (MLP-260): сенобургер (burger-набор, всегда) + горизонтальные
 * пункты header-набора — общий партиал nav.php (MLP-266) — справа от лого.
 * Порядок бургер/лого/горизонталь задаётся CSS-order в .video-container .header.
 * На мобиле горизонталь прячется глобальным правилом .site-nav (≤768px).
 */
$path = $arResult['current_path'];

if (!empty($arResult['items'])) {
    $panelItems = $arResult['items'];
    require dirname(__DIR__, 2) . '/panel.php';
}

$navItems = $arResult['nav_items'] ?? [];
$navClass = 'site-nav-stream';
require dirname(__DIR__, 2) . '/nav.php';
