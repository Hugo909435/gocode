/*
 * Service worker gocode — notifications push + cycle de vie PWA.
 *
 * Pas de cache offline volontairement : l'app est inutilisable sans réseau
 * (tout passe par l'API), un cache ne ferait que servir des assets périmés.
 */

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// ─── Push ────────────────────────────────────────────────────────────
// Payload attendu (JSON) : { title, body, session_id, type }

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'gocode';
    const options = {
        body: payload.body || '',
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        tag: payload.session_id ? `session-${payload.session_id}` : undefined,
        data: {
            sessionId: payload.session_id || null,
            type: payload.type || null,
        },
        // Les confirmations exigent une action — la notification reste affichée
        requireInteraction: payload.type === 'confirmation_request',
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Clic sur la notification → focus de l'app sur la session concernée
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const sessionId = event.notification.data?.sessionId;
    const url = sessionId ? `/sessions/${sessionId}` : '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) {
                    client.focus();
                    if ('navigate' in client) client.navigate(url);
                    return;
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
