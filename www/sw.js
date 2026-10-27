/* Service worker di sVolta: notifiche push e funzionamento offline. */
const VERSION = 'svolta-v3';
const SHELL = ['./', 'index.html', 'style.css', 'app.js', 'manifest.webmanifest', 'icons/icon-192.png', 'icons/badge-96.png'];
const STATIC_HOSTS = ['cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION && k !== 'svolta-pdf').map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // PDF: dalla memoria se già aperti, così si rileggono anche offline
  if (url.origin === location.origin && url.pathname.endsWith('/api/pdf.php')) {
    e.respondWith(
      caches.open('svolta-pdf').then(async (c) => {
        const hit = await c.match(req);
        if (hit) return hit;
        const res = await fetch(req);
        if (res.ok) {
          c.put(req, res.clone());
          trimPdfCache(c);
        }
        return res;
      })
    );
    return;
  }

  // dati: sempre dalla rete (l'app tiene la sua copia di riserva)
  if (url.origin === location.origin && url.pathname.includes('/api/')) return;

  // lettore PDF e caratteri: memoria, aggiornata in background
  if (STATIC_HOSTS.includes(url.hostname)) {
    e.respondWith(
      caches.open(VERSION).then(async (c) => {
        const hit = await c.match(req);
        const net = fetch(req).then((res) => { if (res.ok || res.type === 'opaque') c.put(req, res.clone()); return res; }).catch(() => hit);
        return hit || net;
      })
    );
    return;
  }

  // file dell'app: rete prima, memoria se offline
  if (url.origin === location.origin) {
    e.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok) caches.open(VERSION).then((c) => c.put(req, res.clone()));
          return res;
        })
        .catch(async () => (await caches.match(req, { ignoreSearch: req.mode === 'navigate' })) || caches.match('index.html'))
    );
  }
});

async function trimPdfCache(c) {
  const keys = await c.keys();
  for (let i = 0; i < keys.length - 40; i++) await c.delete(keys[i]);
}

/* ---------------- notifiche ---------------- */

self.addEventListener('push', (e) => {
  let data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) { data = { title: 'Nuova circolare', body: e.data ? e.data.text() : '' }; }
  const title = data.title || 'Nuova circolare';
  const options = {
    body: data.body || '',
    icon: 'icons/icon-192.png',
    badge: 'icons/badge-96.png',
    tag: data.guid ? 'circ-' + data.guid : 'svolta',
    data: { url: data.url || './', guid: data.guid || '' },
    lang: 'it',
  };
  e.waitUntil(
    self.registration.showNotification(title, options).then(() =>
      self.clients.matchAll({ type: 'window' }).then((list) => list.forEach((c) => c.postMessage({ type: 'refresh' })))
    )
  );
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const { url, guid } = e.notification.data || {};
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      const open = list.find((c) => new URL(c.url).pathname.startsWith(new URL(self.registration.scope).pathname));
      if (open) {
        if (guid) open.postMessage({ type: 'open', guid });
        return open.focus();
      }
      return self.clients.openWindow(url || './');
    })
  );
});

// il browser ha rinnovato l'iscrizione: la comunico al server
self.addEventListener('pushsubscriptionchange', (e) => {
  e.waitUntil((async () => {
    const api = new URL('api/', self.registration.scope);
    if (e.oldSubscription) {
      await fetch(new URL('unsubscribe.php', api), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint: e.oldSubscription.endpoint }),
      }).catch(() => {});
    }
    const { publicKey } = await (await fetch(new URL('vapid.php', api))).json();
    const pad = '='.repeat((4 - (publicKey.length % 4)) % 4);
    const raw = atob((publicKey + pad).replace(/-/g, '+').replace(/_/g, '/'));
    const key = Uint8Array.from(raw, (ch) => ch.charCodeAt(0));
    const sub = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    await fetch(new URL('subscribe.php', api), {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub.toJSON()),
    });
  })());
});
