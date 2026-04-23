const CACHE_NAME = 'pulse-hrms-v3';
const STATIC_ASSETS = ['/offline.html'];

// On install: only cache the offline fallback page, NOT auth-protected pages.
self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(STATIC_ASSETS);
    })
  );
});

// On activate: delete old caches so stale auth pages are gone immediately.
self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (key) { return key !== CACHE_NAME; })
            .map(function (key) { return caches.delete(key); })
      );
    }).then(function () { return clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  // Only handle GET requests.
  if (event.request.method !== 'GET') return;

  var url = new URL(event.request.url);

  // Never intercept: cross-origin requests (Google Fonts, CDNs, etc.)
  if (url.origin !== self.location.origin) return;

  // Never cache HTML / navigation requests — always go network-first.
  // This prevents caching auth redirects on protected pages.
  var isNavigation = event.request.mode === 'navigate' ||
    (event.request.headers.get('Accept') || '').includes('text/html');

  if (isNavigation) {
    event.respondWith(
      fetch(event.request).catch(function () {
        return caches.match('/offline.html');
      })
    );
    return;
  }

  // For static assets (JS, CSS, images): cache-first with network fallback.
  event.respondWith(
    caches.match(event.request).then(function (cached) {
      return cached || fetch(event.request).then(function (response) {
        // Only cache successful same-origin asset responses.
        if (response && response.status === 200) {
          var clone = response.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(event.request, clone);
          });
        }
        return response;
      });
    })
  );
});
