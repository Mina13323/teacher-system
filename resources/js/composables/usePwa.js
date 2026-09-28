import { ref, onMounted, onUnmounted } from 'vue';
import { useRegisterSW } from 'virtual:pwa-register/vue';

const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
const isInstallable = ref(false);
const isInstalled = ref(false);
const deferredPrompt = ref(null);
const isIos = ref(false);

// PWA registration must be shared by every component that consumes it.
// Registering once per banner creates separate `needRefresh` refs and can
// leave the visible button disconnected from the worker that is waiting.
let registration;

function getRegistration() {
    if (!registration) {
        registration = useRegisterSW({
            immediate: true,
            onRegisteredSW(_swUrl, serviceWorkerRegistration) {
                // Ask for a new deployment regularly while the PWA is open.
                // The update remains user-controlled through the refresh button.
                if (serviceWorkerRegistration) {
                    setInterval(() => serviceWorkerRegistration.update(), 60 * 60 * 1000);
                }
            },
        });
    }

    return registration;
}

export function usePwa() {
    const { needRefresh, updateServiceWorker } = getRegistration();

    function updateOnlineStatus() {
        isOnline.value = navigator.onLine;
    }

    function checkInstalled() {
        if (typeof window === 'undefined') return;
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        isInstalled.value = isStandalone;
    }

    function checkIos() {
        if (typeof navigator === 'undefined') return;
        isIos.value = /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.MSStream;
    }

    function handleBeforeInstallPrompt(e) {
        e.preventDefault();
        deferredPrompt.value = e;
        isInstallable.value = true;
    }

    async function promptInstall() {
        if (!deferredPrompt.value) return false;
        deferredPrompt.value.prompt();
        const { outcome } = await deferredPrompt.value.userChoice;
        if (outcome === 'accepted') {
            isInstallable.value = false;
        }
        deferredPrompt.value = null;
        return outcome === 'accepted';
    }

    onMounted(() => {
        checkInstalled();
        checkIos();

        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
        window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
        window.addEventListener('appinstalled', () => {
            isInstalled.value = true;
            isInstallable.value = false;
        });
    });

    onUnmounted(() => {
        window.removeEventListener('online', updateOnlineStatus);
        window.removeEventListener('offline', updateOnlineStatus);
        window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    });

    return {
        isOnline,
        isInstallable,
        isInstalled,
        isIos,
        needRefresh,
        updateServiceWorker,
        promptInstall,
    };
}
