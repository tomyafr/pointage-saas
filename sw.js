// Service Worker for Raoul Lenoir Pointage
const CACHE_NAME = 'raoul-lenoir-v2';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

// Listen for messages from the client
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SHOW_NOTIFICATION') {
        const title = event.data.title || 'Pointage Industriel';
        const options = {
            body: event.data.message,
            icon: '/assets/icon-192.png',
            badge: '/assets/icon-192.png',
            vibrate: [200, 100, 200],
            data: {
                url: self.registration.scope
            }
        };
        self.registration.showNotification(title, options);
    }
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window' }).then((clientList) => {
            for (const client of clientList) {
                if (client.url === event.notification.data.url && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(event.notification.data.url);
            }
        })
    );
});
