import { ref } from 'vue';
import api from '@/api/client.js';

/**
 * Gestion de l'abonnement aux notifications Web Push.
 *
 * Usage : const { supported, enabled, busy, init, toggle } = usePush();
 * - init() au montage pour refléter l'état réel de l'abonnement
 * - toggle() sur le bouton cloche
 *
 * Sur iOS, le push n'est disponible que si l'app est installée en PWA
 * (écran d'accueil) — `supported` sera false dans Safari classique.
 */

// Convertit la clé publique VAPID base64url en Uint8Array pour PushManager
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

export function usePush() {
    const supported = ref(
        'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window,
    );
    const enabled = ref(false);
    const busy = ref(false);
    const serverConfigured = ref(true);

    async function init() {
        if (!supported.value) return;
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            enabled.value = subscription !== null;
        } catch {
            enabled.value = false;
        }
    }

    async function toggle() {
        if (!supported.value || busy.value) return;
        busy.value = true;
        try {
            if (enabled.value) {
                await unsubscribe();
            } else {
                await subscribe();
            }
        } catch (e) {
            console.warn('Push toggle failed:', e);
        } finally {
            busy.value = false;
        }
    }

    async function subscribe() {
        const { data } = await api.get('/push/key');
        if (!data.enabled || !data.key) {
            serverConfigured.value = false;
            return;
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return;

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(data.key),
        });

        await api.post('/push/subscribe', subscription.toJSON());
        enabled.value = true;
    }

    async function unsubscribe() {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            await api.delete('/push/subscribe', { data: { endpoint: subscription.endpoint } });
            await subscription.unsubscribe();
        }
        enabled.value = false;
    }

    return { supported, enabled, busy, serverConfigured, init, toggle };
}
