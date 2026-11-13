// Service Worker for TechWorld (public/ scope)

const VERSION = 'v6.0.0';
const CACHE_NAME = `techworld-${VERSION}`;

// Resolve base path dynamically (sw is served from /.../public/sw.js)
const BASE_PATH = (() => {
  const url = new URL(self.location.href);
  // e.g. /TECHWRLD/public/sw.js -> /TECHWRLD/public/
  // or http://ip/public/sw.js -> /public/
  let path = url.pathname.replace(/sw\.js$/, '');
  // Ensure path starts with /
  if (!path.startsWith('/')) path = '/' + path;
  // Ensure path ends with /
  if (!path.endsWith('/')) path = path + '/';
  return path;
})();

// App shell assets (core UI that should work offline)
const APP_SHELL = [
  `${BASE_PATH}shared/offline.html`,
  `${BASE_PATH}shared/style.css`,
  `${BASE_PATH}shared/assets/js/main.js`,
  `${BASE_PATH}shared/img/Logo.ico`,
  `${BASE_PATH}shared/manifest.php`
];

// Helpful pages to pre-cache for guest users
const GUEST_PAGES = [
  `${BASE_PATH}guest/index.php`,
  `${BASE_PATH}guest/products.php`,
  `${BASE_PATH}guest/download.php`
];

// CDN assets that are safe to cache
const CDN_ASSETS = [
  'https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css',
  'https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css',
  'https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css',
  'https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css',
  'https://cdn-uicons.flaticon.com/uicons-regular/css/uicons-regular.css',
  'https://cdn-uicons.flaticon.com/uicons-rounded/css/uicons-rounded.css'
];

self.addEventListener('install', (event) => {
  console.log('[SW] Installing service worker...');
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[SW] Caching app shell...');
      // Cache critical assets first, ignore failures for optional ones
      return Promise.allSettled([
        ...APP_SHELL.map(url => cache.add(url).catch(err => console.warn('[SW] Failed to cache:', url, err))),
        ...GUEST_PAGES.map(url => cache.add(url).catch(err => console.warn('[SW] Failed to cache:', url, err)))
      ]).then(() => {
        console.log('[SW] App shell cached');
        return self.skipWaiting();
      });
    })
  );
});

self.addEventListener('activate', (event) => {
  console.log('[SW] Activating service worker...');
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((k) => {
          if (k !== CACHE_NAME) {
            console.log('[SW] Deleting old cache:', k);
            return caches.delete(k);
          }
          return Promise.resolve();
        })
      );
    }).then(() => {
      console.log('[SW] Service worker activated');
      return self.clients.claim();
    })
  );
});

// Decide which requests to cache at runtime
function shouldCache(urlString) {
  try {
    const url = new URL(urlString);
    const pathname = url.pathname;
    const staticExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2'];
    if (staticExtensions.some((ext) => pathname.endsWith(ext))) return true;
    const cacheablePages = ['/index.php', '/products.php', '/product.php', '/search.php'];
    if (cacheablePages.some((p) => pathname.endsWith(p))) return true;
  } catch (_) { /* ignore */ }
  return false;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Pass through non-GET or PHP with query (dynamic)
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.search && url.pathname.endsWith('.php')) return;

  // App shell network-first for navigation; else cache-first for assets
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).then((response) => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        return response;
      }).catch(() => caches.match(request).then((resp) => resp || caches.match(`${BASE_PATH}shared/offline.html`)))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request).then((net) => {
        if (net && net.status === 200 && net.type === 'basic' && shouldCache(request.url)) {
          const copy = net.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return net;
      }).catch(() => {
        // Fallback for images
        if (request.destination === 'image') {
          return new Response('', { status: 200 });
        }
        return new Response('Offline', { status: 200 });
      });
    })
  );
});

// Background Sync scaffold
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-queued-actions') {
    event.waitUntil(Promise.resolve());
  }
});

// Push Notifications
self.addEventListener('push', (event) => {
  const dataText = event.data ? event.data.text() : 'New notification from TechWorld';
  const options = {
    body: dataText,
    icon: `${BASE_PATH}shared/assets/images/icons/icon-192.png`,
    badge: `${BASE_PATH}shared/assets/images/icons/icon-96.png`,
    vibrate: [200, 100, 200],
    tag: 'techworld-notification'
  };
  event.waitUntil(self.registration.showNotification('TechWorld', options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(clients.openWindow(`${BASE_PATH}guest/index.php`));
});