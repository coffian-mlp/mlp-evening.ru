// Версия кеша включает релиз и ревизию статических ассетов. Обновлять при их замене:
// смена имени заставляет activate-обработчик выбросить старый кеш ассетов на проде.
const CACHE_VERSION = 'v4.16.0-mlp448';
const CACHE_NAME = 'mlp-evening-' + CACHE_VERSION;
const ASSETS_TO_CACHE = [
    '/assets/css/main.css',
    '/assets/css/dashboard.css',
    '/assets/css/fonts.css',
    '/assets/css/themes.css',
    '/assets/js/theme.js',
    '/assets/js/main.js',
    '/assets/js/lyra-notifications.js',
    '/assets/js/centrifuge-5.0.1.js',
    '/assets/js/ux-statistics.js',
    '/src/Components/Chat/assets/chat-core.css',
    '/src/Components/Chat/assets/chat-core.js',
    '/src/Components/Chat/assets/keyword-alerts.js',
    '/src/Components/Chat/assets/command-interactions.js',
    '/src/Components/StreamReactions/templates/default/script.js',
    '/src/Components/StreamReactions/templates/default/style.css',
    '/src/Components/VideoPlayer/templates/default/script.js',
    '/src/Components/VideoPlayer/templates/default/style.css',
    '/src/Components/EveningArchive/templates/default/style.css',
    '/src/Components/Chat/templates/popup/style.css', // MLP-289: офлайн-попап без него терял оверрайды
    '/assets/js/dashboard.js',
    '/assets/js/jquery.min.js',
    '/assets/img/logo.png',
    '/assets/img/themes/luna-background.webp',
    '/assets/img/themes/celestia-background.webp',
    '/assets/img/themes/luna-logo.webp',
    '/assets/img/themes/celestia-logo.webp',
    '/assets/img/default-avatar.png',
    '/favicon.png',
    '/assets/img/icon-192.png',
    '/assets/img/icon-512.png',
    '/assets/img/icon-maskable-512.png',
    '/assets/img/apple-touch-icon.png',
    '/manifest.json'
];

// 1. Install Event: Cache Core Assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE);
        })
    );
});

// 2. Activate Event: Clean Old Caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache);
                    }
                })
            );
        })
    );
});

// 3. Fetch Event: Network First for HTML/API, Cache First for Assets
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.pathname.startsWith('/live/')) return;

    // API & Dynamic Pages -> Network First, fall back to nothing (or offline page if we had one)
    if (url.pathname.includes('api.php') || 
        url.pathname.endsWith('.php') || 
        url.pathname === '/' || 
        (event.request.headers.get('accept') || '').includes('text/html')) {
        
        event.respondWith(
            fetch(event.request).catch(() => {
                // Optional: Return a custom offline page here
                // return caches.match('/offline.html');
                return caches.match(event.request); // Try cache as last resort
            })
        );
        return;
    }

    // Static Assets -> Cache First, fall back to Network
    event.respondWith(
        caches.match(event.request).then((response) => {
            return response || fetch(event.request).then((fetchResponse) => {
                // Optionally cache new static assets on the fly?
                // For now, keep it simple.
                return fetchResponse;
            });
        })
    );
});
