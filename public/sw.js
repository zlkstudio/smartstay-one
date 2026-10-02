/* SmartStay ONE — service worker.
   Shell assets: cache-first (URLs carry ?v=filemtime, so a new file = a new URL).
   Pages and /api/: always network — authenticated HTML and live data are never cached.
   Offline navigation → /offline.html. */
'use strict';

const CACHE = 'one-shell-v2';
const PRECACHE = [
  '/offline.html',
  '/assets/fonts/jost-latin-400-normal.woff2',
  '/assets/fonts/jost-latin-500-normal.woff2',
  '/assets/fonts/jost-latin-600-normal.woff2',
  '/assets/fonts/jost-latin-700-normal.woff2',
  '/assets/fonts/jost-latin-ext-400-normal.woff2',
  '/assets/fonts/jost-latin-ext-600-normal.woff2',
  '/assets/img/icon-192.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
    return;
  }

  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(cacheFirst(request, url));
  }
  // Everything else (/api/*, manifest, sw) goes straight to the network.
});

async function cacheFirst(request, url) {
  const cache = await caches.open(CACHE);
  const hit = await cache.match(request);
  if (hit) return hit;

  const response = await fetch(request);
  if (response.ok) {
    // Drop older versions of the same file (?v=old) so the cache doesn't grow forever.
    const keys = await cache.keys();
    await Promise.all(keys
      .filter((k) => { const u = new URL(k.url); return u.pathname === url.pathname && u.search !== url.search; })
      .map((k) => cache.delete(k)));
    await cache.put(request, response.clone());
  }
  return response;
}
