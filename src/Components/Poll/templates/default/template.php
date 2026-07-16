<?php
/** Точка монтирования опроса; PollWidget подтянет данные и отрисует (MLP-239). */
if (!empty($arResult['pollId'])): ?>
<div class="poll-widget" data-poll-id="<?= (int)$arResult['pollId'] ?>"></div>
<?php endif; ?>
