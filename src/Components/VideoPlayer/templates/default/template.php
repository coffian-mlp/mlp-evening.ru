<?php
/**
 * @var array $arResult
 */
?>
<div class="video-content">
    <iframe 
        src="<?= htmlspecialchars($arResult['stream_url']) ?>" 
        allowfullscreen 
        allow="autoplay">
    </iframe>
</div>
