import { ref, onMounted } from 'vue';
import { useRegisterSW } from 'virtual:pwa-register/vue';

const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
const isInstallable = ref(false);
const isInstalled = ref(false);
const deferredPrompt = ref(null);
const isIos = ref(false);

// PWA registration and browser event listeners are shared by every component
// that consumes this composable. A single waiting worker must drive every
// update prompt, and a single set of listeners avoids duplicated app events.
let registration;
let serviceWorkerRegistration;
let updateCheckTimer;
let browserListenersAttached = false;

function checkForUpdates() {
    if (!serviceWorkerRegistration || typeof navigator === 'undefined' || !navigator.onLine) return;
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;

    serviceWorkerRegistration.update().catch(() => {
        // Network failures are normal while offline; the current worker stays active.
    });
}

function handleVisibilityChange() {
    if (document.visibilityState === 'visible') checkForUpdates();
}

function updateOnlineStatus() {
    if (typeof navigator !== 'undefined') isOnline.value = navigator.onLine;
    if (isOnline.value) checkForUpdates();
}

function checkInstalled() {
    if (typeof window === 'undefined') return;
    const isStandalone = window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
    isInstalled.value = Boolean(isStandalone);
}

function checkIos() {
    if (typeof navigator === 'undefined' || typeof window === 'undefined') return;
    // iPadOS 13+ can identify itself as macOS, so also check for touch input.
    const userAgent = navigator.userAgent || '';
    const iPadOs = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    isIos.value = (/iPhone|iPad|iPod/i.test(userAgent) || iPadOs) && !window.MSStream;
}

function handleBeforeInstallPrompt(event) {
    event.preventDefault();
    deferredPrompt.value = event;
    isInstallable.value = true;
}

function handleAppInstalled() {
    isInstalled.value = true;
    isInstallable.value = false;
    deferredPrompt.value = null;
}

function attachBrowserListeners() {
    if (typeof window === 'undefined' || browserListenersAttached) return;

    checkInstalled();
    checkIos();
    updateOnlineStatus();
    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
    window.addEventListener('focus', checkForUpdates);
    window.addEventListener('pageshow', checkForUpdates);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    window.addEventListener('appinstalled', handleAppInstalled, { once: true });
    browserListenersAttached = true;
}

function getRegistration() {
    if (!registration) {
        registration = useRegisterSW({
            immediate: true,
            onRegisteredSW(_swUrl, registeredWorker) {
                if (!registeredWorker) return;
                serviceWorkerRegistration = registeredWorker;

                // Check immediately after registration, whenever the app returns
                // to the foreground/online, and periodically while it stays open.
                // The waiting worker is still user-confirmed to protect active exams.
                checkForUpdates();
                if (!updateCheckTimer && typeof window !== 'undefined') {
                    updateCheckTimer = window.setInterval(checkForUpdates, 30 * 60 * 1000);
                }
            },
        });
    }

    return registration;
}

export function usePwa() {
    const { needRefresh, updateServiceWorker } = getRegistration();

    onMounted(attachBrowserListeners);

    async function promptInstall() {
        const promptEvent = deferredPrompt.value;
        if (!promptEvent) return false;

        try {
            promptEvent.prompt();
            const { outcome } = await promptEvent.userChoice;
            return outcome === 'accepted';
        } catch {
            return false;
        } finally {
            deferredPrompt.value = null;
            isInstallable.value = false;
        }
    }

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
