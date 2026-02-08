<?php
// src/templates/footer.php
global $app;
?>
    <!-- Global Lightbox -->
    <div id="global-lightbox" class="lightbox" onclick="this.style.display='none'">
        <img id="global-lightbox-img" src="" alt="Zoom">
    </div>

    <!-- Конец контента -->
    <script src="/assets/js/main.js?v=<?= file_exists($_SERVER['DOCUMENT_ROOT'] . '/assets/js/main.js') ? filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/main.js') : time() ?>" charset="utf-8"></script>
    
    <!-- Application Assets (Component JS) -->
    <?php $app->showFooterScripts(); ?>
</body>
</html>
<?php $app->finalize(); ?>
