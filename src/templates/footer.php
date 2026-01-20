    <!-- Global Lightbox -->
    <div id="global-lightbox" class="lightbox" onclick="this.style.display='none'">
        <img id="global-lightbox-img" src="" alt="Zoom">
    </div>

    <!-- Конец контента -->
    <script src="/assets/js/main.js?v=<?= file_exists(__DIR__ . '/../../assets/js/main.js') ? filemtime(__DIR__ . '/../../assets/js/main.js') : time() ?>"></script>
    
    <?php if (isset($extraScripts)) echo $extraScripts; ?>

    <!-- Config for Chat (Passed from index.php) -->
    <script>
        // Pass PHP session data to JS
        // Note: currentUser variables must be available in the scope including this footer
        window.currentUserId = <?= json_encode($currentUser['id'] ?? null) ?>;
        window.currentUserRole = "<?= $currentUser['role'] ?? '' ?>";
        // Pass server time (seconds) to calculate clock skew
        window.serverTime = <?= time() ?>;
        
        // Chat Configuration
        window.chatConfig = {
            driver: "<?= $chatDriver ?? 'sse' ?>",
            centrifugo: {
                url: "<?= $centrifugoUrl ?? '' ?>",
                token: "<?= $centrifugoToken ?? '' ?>"
            }
        };
    </script>

    <!-- Centrifuge JS (only if needed) -->
    <?php if (($chatDriver ?? '') === 'centrifugo'): ?>
        <script src="https://unpkg.com/centrifuge@5.0.1/dist/centrifuge.js"></script>
    <?php endif; ?>

    <?php if (isset($enableLocalChat) && $enableLocalChat): ?>
        <script src="/assets/js/local-chat.js?v=<?= file_exists(__DIR__ . '/../../assets/js/local-chat.js') ? filemtime(__DIR__ . '/../../assets/js/local-chat.js') : time() ?>"></script>
    <?php endif; ?>
</body>
</html>
