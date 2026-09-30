import { computed, ref } from 'vue';
import { push } from '@/api';

/**
 * Web Push with graceful fallback (P2).
 *
 * Everything degrades safely: no service worker, no PushManager, denied
 * permission, or missing VAPID key simply means `supported.value` is false or
 * the action returns false — in-app database notifications remain the
 * reliable channel either way.
 */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

export function usePushNotifications() {
    const busy = ref(false);
    const subscribed = ref(false);
    const serverState = ref(null); // { enabled, public_key, subscriptions }
    const error = ref('');

    const supported = computed(
        () =>
            typeof window !== 'undefined' &&
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window
    );

    async function refresh() {
        try {
            serverState.value = await push.subscriptions();
            const endpoint = (await currentSubscription())?.endpoint || null;
            subscribed.value = Boolean(
                endpoint && (serverState.value?.subscriptions || []).some((s) => s.endpoint === endpoint)
            );
        } catch {
            serverState.value = null;
        }
    }

    async function currentSubscription() {
        if (!supported.value) return null;
        const reg = await navigator.serviceWorker.ready;
        return reg.pushManager.getSubscription();
    }

    async function enable() {
        if (!supported.value || busy.value) return false;
        busy.value = true;
        error.value = '';
        try {
            if (!serverState.value) await refresh();
            const publicKey = serverState.value?.public_key;
            if (!publicKey) {
                error.value = 'unavailable';
                return false;
            }

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                error.value = 'denied';
                return false;
            }

            const reg = await navigator.serviceWorker.ready;
            let sub = await reg.pushManager.getSubscription();
            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicKey),
                });
            }

            await push.subscribe(sub.toJSON());
            subscribed.value = true;
            return true;
        } catch (e) {
            error.value = e?.message || 'failed';
            return false;
        } finally {
            busy.value = false;
        }
    }

    async function disable() {
        if (busy.value) return false;
        busy.value = true;
        error.value = '';
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (sub) {
                // Remove from the server first (by endpoint), then unsubscribe locally.
                const list = (serverState.value?.subscriptions || []);
                const match = list.find((s) => s.endpoint === sub.endpoint);
                if (match) await push.unsubscribe(match.id);
                await sub.unsubscribe();
            }
            subscribed.value = false;
            return true;
        } catch (e) {
            error.value = e?.message || 'failed';
            return false;
        } finally {
            busy.value = false;
        }
    }

    return { supported, subscribed, busy, error, refresh, enable, disable };
}
