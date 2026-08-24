const CACHE_NAME = 'mkpos-mobile-shell-v1';
const scopeUrl = new URL(self.registration.scope);
const appShellUrl = scopeUrl.href;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll([
        appShellUrl,
        new URL('manifest.webmanifest', scopeUrl).href,
        new URL('branding/mkicon.png', scopeUrl).href,
        new URL('branding/mktransparenticon.png', scopeUrl).href,
      ]))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key.startsWith('mkpos-mobile-shell-') && key !== CACHE_NAME).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== scopeUrl.origin || url.pathname.includes('/api/') || url.pathname.includes('/sanctum/')) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) void caches.open(CACHE_NAME).then((cache) => cache.put(appShellUrl, response.clone()));
          return response;
        })
        .catch(() => caches.match(appShellUrl)),
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (response.ok && ['script', 'style', 'font', 'image'].includes(request.destination)) {
        void caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
      }
      return response;
    })),
  );
});
