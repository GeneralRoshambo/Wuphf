// Wuphf service worker. Caches only static assets; pages always come from the
// network (they are per-user), with a small offline page as the fallback.
const V = 'wuphf-v28';
const STATIC = ['assets/style.css', 'assets/app.js', 'assets/icons/icon-192.png', 'offline.html'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(V).then(c => c.addAll(STATIC)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== V).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const r = e.request, url = new URL(r.url);
  if (r.method !== 'GET' || url.origin !== location.origin) return;
  if (r.mode === 'navigate') {
    e.respondWith(fetch(r).catch(() => caches.match('offline.html')));
  } else if (url.pathname.includes('/assets/')) {
    // Network first so updates show immediately; the cache only covers being offline.
    e.respondWith(fetch(r).then(res => {
      if (res.ok) { const copy = res.clone(); caches.open(V).then(c => c.put(r, copy)); }
      return res;
    }).catch(() => caches.match(r)));
  }
});
