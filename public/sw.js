/* Haman Planner service worker.
 * Caches only the static app shell (JS, fonts, icons) and an offline page.
 * Private data is never cached: API responses, dashboard HTML, account, billing and admin pages
 * always go to the network. Logging out clears these caches (Clear-Site-Data: "cache").
 */
const VERSION = 'hp-shell-v1';
const SHELL = ['/offline', '/js/planner-calendar.js', '/js/planner-ai.js', '/fonts/vazirmatn/Vazirmatn-wght.woff2',
  '/fonts/poppins/poppins-latin-400-normal.woff2', '/fonts/poppins/poppins-latin-700-normal.woff2', '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

const isStatic = url => url.origin === self.location.origin && /^\/(js|fonts|icons)\//.test(url.pathname);

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Static assets: cache first, refreshed in the background.
  if (isStatic(url)) {
    e.respondWith(caches.open(VERSION).then(async c => {
      const hit = await c.match(req, { ignoreSearch: true });
      const net = fetch(req).then(r => { if (r.ok) c.put(req, r.clone()); return r; }).catch(() => hit);
      return hit || net;
    }));
    return;
  }

  // Page navigations: always the network; the offline page only when there is no connection.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/offline')));
  }
  // Everything else (API, uploads, feeds) is left to the browser — never cached here.
});

self.addEventListener('message', e => {
  if (e.data === 'clear') e.waitUntil(caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))));
});
