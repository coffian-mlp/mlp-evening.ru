<?php
/**
 * @var array $arResult
 * Сенобургер (MLP-259): главная, поверх плеера. Закрытие — клик мимо/Esc.
 */
if (empty($arResult['items'])) return;
$panelItems = $arResult['items'];
$path = $arResult['current_path'];
require dirname(__DIR__, 2) . '/panel.php';
