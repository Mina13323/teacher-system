import { ref, onMounted, onUnmounted } from 'vue';
import { useRegisterSW } from 'virtual:pwa-register/vue';

const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
const isInstallable = ref(false);
const isInstalled = ref(false);
const deferredPrompt = ref(null);
const isIos = ref(false);

export function usePwa() {
    const { needRefresh, updateServiceWorker } = useRegisterSW({
        immediate: true,
        onRegisteredSW(_swUrl, registration) {
            // Ask for a new deployment regularly while the PWA is open. The
            // update is still user-controlled through the refresh button.
            if (registration) setInterval(() => registration.update(), 60 * 60 * 1000);
        },
    });

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
