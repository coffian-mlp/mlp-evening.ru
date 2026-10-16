<?php
require_once __DIR__ . '/init.php';
\Domain\Auth::requireLogin();
$app->setTitle('Профиль — MLP Evening');
$bodyClass = 'profile-page'; $showPageHeader = true;
require __DIR__ . '/src/templates/header.php';
$app->includeComponent('Profile');
require __DIR__ . '/src/templates/footer.php';
