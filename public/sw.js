const CACHE_PREFIX = 'ngwe-lwe-static-';
const CACHE_NAME = `${CACHE_PREFIX}v2`;
const OFFLINE_URL = '/offline.html';

const APP_SHELL = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/favicon.svg',
    '/apple-touch-icon.png',
    '/pwa-icon-192.png',
    '/pwa-icon-512.png',
    '/pwa-icon-maskable-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) =>
                                key.startsWith(CACHE_PREFIX) &&
                                key !== CACHE_NAME,
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Financial/navigation requests always go to the network.
    // No authenticated HTML, Inertia response, transaction, account, float,
    // login, logout, or form submission response is written to Cache Storage.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );
        return;
    }

    // Only immutable Vite build assets and explicit public app-shell assets
    // are cacheable. Runtime business/data requests are intentionally excluded.
    const isBuildAsset = url.pathname.startsWith('/build/assets/');
    const isShellAsset = APP_SHELL.includes(url.pathname);

    if (!isBuildAsset && !isShellAsset) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(request).then((response) => {
                if (!response.ok || response.type !== 'basic') {
                    return response;
                }

                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(request, copy);
                });

                return response;
            });
        }),
    );
});
