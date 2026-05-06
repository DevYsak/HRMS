self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil((async function () {
        var cacheKeys = await caches.keys();

        await Promise.all(cacheKeys.map(function (key) {
            return caches.delete(key);
        }));

        if (self.registration) {
            await self.registration.unregister();
        }

        await self.clients.claim();

        var windowClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        windowClients.forEach(function (client) {
            client.postMessage({ type: 'SW_DISABLED' });
        });
    })());
});

self.addEventListener('fetch', function () {
    // Intentionally empty. PWA caching is disabled for now.
});
