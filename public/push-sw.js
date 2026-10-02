/*
 * Web Push handlers for the PWA (P2).
 *
 * Kept as a standalone file (imported by the service worker) so push support
 * survives both the committed build artifact and future VitePWA rebuilds
 * (vite.config.js sets `importScripts: ['/push-sw.js']`).
 */
function safeInternalNotificationUrl(candidate) {
    var origin = self.location.origin;
    var home = new URL('/', origin).href;
    if (typeof candidate !== 'string' || candidate.length > 2048) return home;
    try {
        var target = new URL(candidate, origin);
        if (target.origin !== origin || target.username || target.password) return home;
        return target.href;
    } catch (e) {
        return home;
    }
}

self.addEventListener('push', function (event) {
    var payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { body: event.data ? event.data.text() : '' };
    }
    var title = payload.title || 'El Masry';
    var data = Object.assign({}, payload.data || {});
    // Never let either top-level payload.url or payload.data.url open another
    // origin when the user taps a push notification.
    data.url = safeInternalNotificationUrl(payload.url || data.url || '/');
    var options = {
        body: payload.body || '',
        icon: '/pwa-192x192.png',
        badge: '/pwa-192x192.png',
        data: data,
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = safeInternalNotificationUrl(event.notification.data && event.notification.data.url);
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
