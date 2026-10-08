<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import { student } from '@/api';
import { createPlaybackEventGate, isBlurIntoPlayer } from '@/utils/playbackEventGate';

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

// The events are an audit record: each type goes to the server at most once a
// minute (see playbackEventGate). The on-screen notice is shown every time.
const eventGate = createPlaybackEventGate();

async function report(eventType) {
    if (!session.value?.playback?.token) return;
    noteDetection(DETECT_LABELS[eventType] || eventType);
    if (!eventGate.allow(eventType)) return;
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
const isBlurred = ref(false);

function prevent(e) {
    e.preventDefault();
}

function onKeydown(e) {
    const ctrl = e.ctrlKey || e.metaKey;
    const key = (e.key || '').toLowerCase();
    if (e.key === 'PrintScreen' || (ctrl && key === 'p')) {
        e.preventDefault();
        try { navigator.clipboard?.writeText?.(''); } catch {}
        isBlurred.value = true;
        setTimeout(() => { isBlurred.value = false; }, 2500);
        report('KEYBOARD_SHORTCUT');
        return;
    }
    if (flag('block_ctrl_s') && ctrl && key === 's') { e.preventDefault(); return; }
    if (flag('block_ctrl_p') && ctrl && key === 'p') { e.preventDefault(); return; }
    if (flag('block_ctrl_u') && ctrl && key === 'u') { e.preventDefault(); return; }
    if (flag('block_f12') && e.key === 'F12') { e.preventDefault(); return; }
    if (ctrl && e.shiftKey && ['i', 'j', 'c'].includes(key)) e.preventDefault();
}

function onVisibilityChange() {
    if (document.visibilityState === 'hidden') {
        isBlurred.value = true;
        if (flag('detect_tab_switch')) {
            report('TAB_SWITCH');
        }
    } else {
        isBlurred.value = false;
    }
}

function onBlur() {
    isBlurred.value = true;
    if (!flag('detect_window_blur')) return;
    // Focus moves after the blur event: check where it went first.
    setTimeout(() => {
        // A click into the embedded player is not leaving the page, and a tab
        // switch is already reported as TAB_SWITCH. (The shield itself
        // behaves as before; only the server record is skipped.)
        if (isBlurIntoPlayer(document.activeElement, playerWrap.value)) return;
        if (document.visibilityState === 'hidden') return;
        report('WINDOW_BLUR');
    }, 0);
}

function onFocus() {
    isBlurred.value = false;
}

// Simple DevTools detection heuristic (deterrence/audit only).
function detectDevtools() {
    if (!flag('detect_devtools') || document.visibilityState === 'hidden') return;
    const threshold = 160;
    const open = window.outerHeight - window.innerHeight > threshold;
    // Reported when it turns on, not on every poll while it stays on.
    if (eventGate.devtoolsTurnedOn(open)) report('DEVTOOLS_DETECTION');
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
    window.addEventListener('focus', onFocus);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    // A lightweight DevTools heuristic poll.
    devtoolsTimer = setInterval(detectDevtools, 1500);
    // Visible-watch estimate for sandboxed embeds (exact for <video>, see above).
    bookmarkTimer = setInterval(tickPositionEstimate, 1000);
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
    window.removeEventListener('focus', onFocus);
    document.removeEventListener('fullscreenchange', onFullscreenChange);

    if (wmTimer) clearInterval(wmTimer);
    if (devtoolsTimer) clearInterval(devtoolsTimer);
    if (bookmarkTimer) clearInterval(bookmarkTimer);
    if (bookmarkHideTimer) clearTimeout(bookmarkHideTimer);

    // Cleared from memory on unmount. Nothing is persisted.
    mediaRef.value = null;
    session.value = null;
});

// ---- Video-timestamp bookmarking (Phase 4 §33 UI hook) ---------------------
const videoEl = ref(null);
const positionSeconds = ref(0);
const bookmarkState = ref(''); // '' | 'saving' | 'saved' | 'failed'
let bookmarkTimer = null;
let bookmarkHideTimer = null;

// Self-hosted video reports the exact currentTime. YouTube embeds are
// sandboxed (no Iframe API in this player), so we keep an honest visible-
// watch estimate — the saved label shows exactly what is stored.
function syncFromVideo() {
    if (videoEl.value && Number.isFinite(videoEl.value.currentTime)) {
        positionSeconds.value = Math.floor(videoEl.value.currentTime);
    }
}

function tickPositionEstimate() {
    if (provider.value !== 'youtube') return;
    if (document.visibilityState === 'visible') {
        positionSeconds.value = Math.min(positionSeconds.value + 1, 86400);
    }
}

const positionLabel = computed(() => {
    const s = Math.max(0, Math.floor(positionSeconds.value));
    const mm = String(Math.floor(s / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    return `${mm}:${ss}`;
});

async function saveBookmark() {
    if (bookmarkState.value === 'saving') return;
    bookmarkState.value = 'saving';
    try {
        await student.addBookmark({
            video_id: props.videoId,
            position_seconds: Math.min(86400, Math.max(0, Math.floor(positionSeconds.value))),
            label: positionLabel.value,
        });
        bookmarkState.value = 'saved';
    } catch {
        bookmarkState.value = 'failed';
    }
    if (bookmarkHideTimer) clearTimeout(bookmarkHideTimer);
    bookmarkHideTimer = setTimeout(() => { bookmarkState.value = ''; }, 2500);
}

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
                class="absolute inset-0 h-full w-full select-none"
                frameborder="0"
                allow="autoplay; encrypted-media"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
                :title="$t('common.videoPlayer')"
            />
            <video
                v-else
                ref="videoEl"
                :src="storageSrc"
                controls
                @timeupdate="syncFromVideo"
                @seeked="syncFromVideo"
                @loadedmetadata="syncFromVideo"
                class="absolute inset-0 h-full w-full select-none"
                controlslist="nodownload noplaybackrate noremoteplayback"
                disablePictureInPicture
                disableRemotePlayback
                @contextmenu.prevent
            />

            <!-- Video-timestamp bookmark (Phase 4 §33): one click saves the
                 current moment to the student's personal bookmarks. -->
            <div class="absolute end-3 top-3 z-20 flex items-center gap-2">
                <span
                    v-if="bookmarkState"
                    class="rounded-full bg-black/70 px-2.5 py-0.5 text-[11px] text-white"
                    :class="{ 'bg-emerald-600/80': bookmarkState === 'saved', 'bg-rose-600/80': bookmarkState === 'failed' }"
                >
                    {{
                        bookmarkState === 'saving'
                            ? $t('lesson.videoBookmarkSaving')
                            : bookmarkState === 'saved'
                                ? $t('lesson.videoBookmarkSaved', { time: positionLabel })
                                : $t('lesson.videoBookmarkFailed')
                    }}
                </span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full bg-black/70 px-3 py-1.5 text-xs font-medium text-white shadow hover:bg-black/85 focus:outline-none focus:ring-2 focus:ring-white/60"
                    :disabled="bookmarkState === 'saving'"
                    @click="saveBookmark"
                >
                    <span aria-hidden="true">🔖</span>
                    <span>{{ $t('lesson.videoBookmarkBtn') }} · {{ positionLabel }}</span>
                </button>
            </div>

            <!-- Privacy Shield on blur / screen-capture attempt -->
            <div
                v-if="isBlurred"
                class="absolute inset-0 z-30 flex flex-col items-center justify-center bg-black/95 p-6 text-center text-white backdrop-blur-md transition-opacity duration-150"
            >
                <div class="mb-2 text-3xl">🛡️</div>
                <p class="text-base font-bold text-amber-300">محتوى الفيديو محمي</p>
                <p class="mt-1 text-xs text-ink-300">لا يُسمح بتسجيل الشاشة أو التقاط صور أثناء تشغيل المحتوى.</p>
                <div v-if="watermarkText" class="mt-3 rounded border border-white/20 bg-white/10 px-3 py-1 font-mono text-[11px] text-white/80">
                    {{ watermarkText }}
                </div>
            </div>

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
