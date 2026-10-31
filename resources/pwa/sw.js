/*
 * The panel's service worker — docs/desktop-mobile-plan.md M1 and M2.
 *
 * It does two things and refuses a third. It caches the shell it needs to say "you're offline" and answers a
 * failed navigation with that page; it receives pushes and opens the right screen when one is tapped. It does
 * NOT cache data: a payslip or a balance read from a cache is a figure nobody can vouch for, and the offline
 * capture that §5a designs is a sync engine, not a cache. Bump CACHE when the offline page changes.
 */
const CACHE = 'mizan-shell-v1';
const OFFLINE_URL = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

// Navigations only. Assets, Livewire and the API go straight to the network, so nothing stale is ever served.
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => caches.open(CACHE).then((cache) => cache.match(OFFLINE_URL)))
    );
});

// M2. The payload is the bell's own shape (title, body, url) — see App\Notifications\Channels\WebPushChannel.
self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Mizan';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/images/logo-mark.png',
        badge: '/images/logo-mark.png',
        tag: payload.tag || undefined,
        data: { url: payload.url || '/' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client && new URL(client.url).origin === self.location.origin) {
                    client.navigate(url);

                    return client.focus();
                }
            }

            return self.clients.openWindow(url);
        })
    );
});
