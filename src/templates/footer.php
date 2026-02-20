<?php
// src/templates/footer.php
global $app;
?>
    <!-- Global Smart Lightbox -->
    <div id="global-lightbox" class="lightbox" style="display: none;">
        <button id="lightbox-close" class="lightbox-close-btn">&times;</button>
        <div class="lightbox-content">
            <img id="global-lightbox-img" src="" alt="Full Image" draggable="false">
        </div>
        <div class="lightbox-controls">
            <span id="lightbox-zoom-level">100%</span>
            <button id="lightbox-zoom-in" title="Zoom In">+</button>
            <button id="lightbox-zoom-out" title="Zoom Out">-</button>
            <button id="lightbox-reset" title="Reset">⟲</button>
        </div>
    </div>

    <!-- Конец контента -->
    <script src="/assets/js/main.js?v=<?= file_exists($_SERVER['DOCUMENT_ROOT'] . '/assets/js/main.js') ? filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/main.js') : time() ?>" charset="utf-8"></script>
    
    <!-- Application Assets (Component JS) -->
    <?php $app->showFooterScripts(); ?>
</body>
</html>
<?php $app->finalize(); ?>
