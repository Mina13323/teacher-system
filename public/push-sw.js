/*
 * Web Push handlers for the PWA (P2).
 *
 * Kept as a standalone file (imported by the service worker) so push support
 * survives both the committed build artifact and future VitePWA rebuilds
 * (vite.config.js sets `importScripts: ['/push-sw.js']`).
 */
self.addEventListener('push', function (event) {
    var payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { body: event.data ? event.data.text() : '' };
    }
    var title = payload.title || 'El Masry';
    var options = {
        body: payload.body || '',
        icon: '/pwa-192x192.png',
        badge: '/pwa-192x192.png',
        data: Object.assign({ url: payload.url || '/' }, payload.data || {}),
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
            for (var i = 0; i < clients.length; i++) {
                if ('focus' in clients[i]) {
                    clients[i].navigate(url);
                    return clients[i].focus();
                }
            }
            return self.clients.openWindow(url);
        })
    );
});
