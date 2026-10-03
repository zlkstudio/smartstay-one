/* SmartStay ONE — service worker.
   Shell assets: cache-first (URLs carry ?v=filemtime, so a new file = a new URL).
   Pages and /api/: always network — authenticated HTML and live data are never cached.
   Offline navigation → /offline.html.
   Push: admin notifications (checklist, inventar) → shown here; a tap opens the related page. */
'use strict';

const CACHE = 'one-shell-v3';
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

// ── Push notifications ──────────────────────────────────────────────────
self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(data.title || 'SmartStay ONE', {
    body: data.body || '',
    tag: data.tag || undefined,
    renotify: !!data.tag && data.renotify !== false, // same tag replaces the old one; notes update quietly
    icon: '/assets/img/icon-192.png',
    badge: '/assets/img/icon-192.png',
    data: { url: data.url || '/activity' }
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || '/activity', self.location.origin).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
    for (const w of wins) {
      if (w.url.startsWith(self.location.origin) && 'focus' in w) {
        return w.focus().then((c) => (c && 'navigate' in c ? c.navigate(target) : c));
      }
    }
    return self.clients.openWindow(target);
  }));
});
