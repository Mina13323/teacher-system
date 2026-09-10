<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import { student } from '@/api';

const props = defineProps({
    videoId: { type: [String, Number], required: true },
    watermarkText: { type: String, default: '' },
});

const loading = ref(true);
const error = ref('');
const session = ref(null);

// The media reference is ONLY held in component memory (volatile). It is never
// written to localStorage/sessionStorage and is cleared on unmount.
const provider = ref(null);
const mediaRef = ref(null);
const embedSrc = ref('');
const storageSrc = ref('');

const PLAYER_BASE = 'https://www.youtube-nocookie.com';

// Deterrence state.
const detected = ref([]);

// Simple per-type throttle so we don't flood the rate-limited endpoint.
const lastReport = ref({});
const DETECT_THROTTLE_MS = 5000;

function throttle(key) {
    const now = Date.now();
    if (lastReport.value[key] && now - lastReport.value[key] < DETECT_THROTTLE_MS) return false;
    lastReport.value[key] = now;
    return true;
}

async function report(eventType) {
    if (!session.value?.playback?.token) return;
    if (!throttle(eventType)) return;
    noteDetection(DETECT_LABELS[eventType] || eventType);
    try {
        await student.playbackEvent(props.videoId, {
            session_token: session.value.playback.token,
            event_type: eventType,
        });
    } catch {
        // Detection reporting must never block playback.
    }
}

function flag(name) {
    return Boolean(session.value?.protection?.[name]);
}

const DETECT_LABELS = {
    FULLSCREEN_EXIT: 'fullscreen exit',
    TAB_SWITCH: 'tab switch',
    WINDOW_BLUR: 'window blur',
    DEVTOOLS_DETECTION: 'devtools',
};

function noteDetection(label) {
    if (!detected.value.includes(label)) detected.value.push(label);
}

// ---- Watermark -------------------------------------------------------------
const watermark = computed(() => session.value?.watermark || {});
const watermarkText = computed(() => {
    if (session.value?.watermark?.text) return session.value.watermark.text;
    return props.watermarkText;
});
const watermarkOpacity = computed(() => watermark.value.opacity ?? 0.18);
const watermarkPositions = ['0% 10%', '40% 40%', '75% 70%'];
const watermarkIndex = ref(0);
let wmTimer = null;

// ---- Fullscreen ------------------------------------------------------------
const playerWrap = ref(null);
const isFullscreen = ref(false);

async function enterFullscreen() {
    const el = playerWrap.value;
    if (!el) return;
    try {
        if (el.requestFullscreen) await el.requestFullscreen();
        else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
    } catch {
        // best-effort
    }
}

function onFullscreenChange() {
    const now = Boolean(document.fullscreenElement);
    if (isFullscreen.value && !now) {
        report('FULLSCREEN_EXIT');
        if (flag('fullscreen_required')) {
            // Re-request a couple of times but never loop forever.
            setTimeout(() => enterFullscreen(), 600);
        }
    }
    isFullscreen.value = now;
}

// ---- Deterrence listeners --------------------------------------------------
function prevent(e) {
    e.preventDefault();
}

function onKeydown(e) {
    const ctrl = e.ctrlKey || e.metaKey;
    const key = (e.key || '').toLowerCase();
    if (flag('block_ctrl_s') && ctrl && key === 's') { e.preventDefault(); return; }
    if (flag('block_ctrl_p') && ctrl && key === 'p') { e.preventDefault(); return; }
    if (flag('block_ctrl_u') && ctrl && key === 'u') { e.preventDefault(); return; }
    if (flag('block_f12') && e.key === 'F12') { e.preventDefault(); return; }
    if (ctrl && e.shiftKey && ['i', 'j', 'c'].includes(key)) e.preventDefault();
}

function onVisibilityChange() {
    if (document.visibilityState === 'hidden' && flag('detect_tab_switch')) {
        report('TAB_SWITCH');
    }
}

function onBlur() {
    if (flag('detect_window_blur')) report('WINDOW_BLUR');
}

// Simple DevTools detection heuristic (deterrence/audit only).
function detectDevtools() {
    if (!flag('detect_devtools')) return;
    const threshold = 160;
    const h = window.outerHeight - window.innerHeight;
    if (h > threshold) {
        if (throttle('devtools')) report('DEVTOOLS_DETECTION');
    }
}

async function loadSession() {
    loading.value = true;
    error.value = '';
    try {
        const res = await student.playback(props.videoId);
        session.value = res;
        provider.value = res.playback.provider;
        mediaRef.value = res.playback.media_ref;

        if (provider.value === 'youtube') {
            const vid = encodeURIComponent(mediaRef.value || '');
            embedSrc.value = `${PLAYER_BASE}/embed/${vid}?rel=0&modestbranding=1&playsinline=1`;
        } else {
            storageSrc.value = mediaRef.value || '';
        }

        if (session.value.protection?.fullscreen_required) {
            setTimeout(() => enterFullscreen(), 300);
        }
        if (watermark.value.rotate_interval_seconds > 0) {
            wmTimer = setInterval(() => {
                watermarkIndex.value = (watermarkIndex.value + 1) % watermarkPositions.length;
            }, watermark.value.rotate_interval_seconds * 1000);
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    loadSession();

    document.addEventListener('contextmenu', prevent);
    document.addEventListener('copy', prevent);
    document.addEventListener('paste', prevent);
    document.addEventListener('cut', prevent);
    document.addEventListener('selectstart', prevent);
    document.addEventListener('dragstart', prevent);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('blur', onBlur);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    // A lightweight DevTools heuristic poll.
    devtoolsTimer = setInterval(detectDevtools, 1500);
});

let devtoolsTimer = null;

onBeforeUnmount(() => {
    document.removeEventListener('contextmenu', prevent);
    document.removeEventListener('copy', prevent);
    document.removeEventListener('paste', prevent);
    document.removeEventListener('cut', prevent);
    document.removeEventListener('selectstart', prevent);
    document.removeEventListener('dragstart', prevent);
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('visibilitychange', onVisibilityChange);
    window.removeEventListener('blur', onBlur);
    document.removeEventListener('fullscreenchange', onFullscreenChange);

    if (wmTimer) clearInterval(wmTimer);
    if (devtoolsTimer) clearInterval(devtoolsTimer);

    // Cleared from memory on unmount. Nothing is persisted.
    mediaRef.value = null;
    session.value = null;
});

function badgeTone(eventType) {
    if (eventType === 'devtools') return 'danger';
    if (eventType === 'window_blur') return 'warning';
    return 'info';
}
</script>

<template>
    <div ref="playerWrap" class="relative aspect-video w-full overflow-hidden bg-ink-900">
        <LoadingSpinner v-if="loading" class="text-white" />
        <div v-else-if="error" class="flex h-full items-center justify-center px-6 text-center text-sm text-rose-300">
            <p>{{ error }}</p>
        </div>

        <template v-else>
            <!-- Provider player embedded INSIDE the teacher-system, never navigated. -->
            <iframe
                v-if="provider === 'youtube'"
                :src="embedSrc"
                class="absolute inset-0 h-full w-full"
                frameborder="0"
                allow="autoplay; encrypted-media; picture-in-picture"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
                :title="$t('common.videoPlayer')"
            />
            <video
                v-else
                :src="storageSrc"
                controls
                class="absolute inset-0 h-full w-full"
                controlslist="nodownload"
                @contextmenu.prevent
            />

            <!-- Watermark overlay -->
            <div
                v-if="watermark.enabled && watermarkText"
                class="pointer-events-none absolute inset-0 overflow-hidden"
                :style="{ opacity: watermarkOpacity }"
            >
                <div
                    v-for="i in 6"
                    :key="i"
                    class="absolute select-none font-semibold uppercase tracking-widest text-white"
                    :style="{ top: watermarkPositions[(i - 1) % watermarkPositions.length], left: watermarkPositions[(i) % watermarkPositions.length], transform: 'rotate(-18deg)' }"
                >
                    {{ watermarkText }}
                </div>
            </div>

            <!-- Detection chips -->
            <div class="absolute bottom-3 start-3 flex flex-wrap gap-2">
                <span
                    v-for="d in detected"
                    :key="d"
                    class="rounded-full bg-black/60 px-2.5 py-0.5 text-[11px] font-medium text-white"
                    :class="{ 'bg-rose-500/70': d === 'devtools' }"
                >
                    {{ d }}
                </span>
            </div>
        </template>
    </div>
</template>
