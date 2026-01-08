    <!-- Global Lightbox -->
    <div id="global-lightbox" class="lightbox" onclick="this.style.display='none'">
        <img id="global-lightbox-img" src="" alt="Zoom">
    </div>

    <!-- Конец контента -->
    <script src="/assets/js/main.js"></script>
    
    <?php if (isset($extraScripts)) echo $extraScripts; ?>

    <?php if (isset($enableLocalChat) && $enableLocalChat): ?>
        <script src="/assets/js/local-chat.js"></script>
    <?php endif; ?>
    <?php if (isset($showChatBro) && $showChatBro): ?>
        <script src="/assets/js/chatbro.js"></script>
    <?php endif; ?>
</body>
</html>
