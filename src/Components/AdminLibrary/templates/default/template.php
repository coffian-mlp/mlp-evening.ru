<?php
/** Administrative authorization is retained by the wrapper component. */
global $app;
$app->includeComponent('EpisodeCatalogue', 'default', ['admin' => true]);
