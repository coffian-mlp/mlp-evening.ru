const CACHE_NAME = 'mlp-evening-v4';
const ASSETS_TO_CACHE = [
    '/assets/css/main.css',
    '/assets/css/chat.css',
    '/assets/css/dashboard.css',
    '/assets/css/fonts.css',
    '/assets/js/main.js',
    '/assets/js/local-chat.js',
    '/assets/js/dashboard.js',
    '/assets/js/jquery.min.js',
    '/assets/img/logo.png',
    '/assets/img/default-avatar.png',
    '/favicon.png',
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

    // API & Dynamic Pages -> Network First, fall back to nothing (or offline page if we had one)
    if (url.pathname.includes('api.php') || 
        url.pathname.endsWith('.php') || 
        url.pathname === '/' || 
        event.request.headers.get('accept').includes('text/html')) {
        
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