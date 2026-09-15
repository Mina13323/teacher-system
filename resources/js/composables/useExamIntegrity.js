import { onBeforeUnmount, readonly, ref } from 'vue';
import { student } from '@/api';

/**
 * Exam proctoring for the student attempt screen.
 *
 * This composable is the only place browser-level monitoring lives. It reads
 * the attempt's FROZEN integrity rules (supplied by the server, never chosen
 * by the client), attaches the matching listeners, reports each event through
 * the existing integrity-events endpoint, and — when the exam is configured to
 * do so — ends the attempt the moment the student leaves the screen.
 *
 * What this can and cannot detect, stated plainly:
 *
 *  CAN detect: leaving the tab, minimizing or backgrounding the app, closing
 *  the page, the window losing focus, leaving fullscreen, copy/paste/cut,
 *  right-click, and shortcut keys including Print Screen.
 *
 *  CANNOT detect: an actual screenshot being taken, or screen recording. No
 *  browser exposes an API for either, so no web app can reliably catch them.
 *  The deterrents here (fullscreen, blocked shortcuts, immediate termination)
 *  raise the cost of cheating; they do not make capture impossible.
 */

/** Events that end the attempt when terminate_on_violation is enabled. */
const TERMINAL_EVENTS = new Set(['TAB_SWITCH', 'WINDOW_BLUR', 'FULLSCREEN_EXIT']);

/**
 * Shortcut keys worth recording. Deliberately a small, well-understood set:
 * clipboard actions, print, view-source, and the usual devtools chords.
 */
const WATCHED_SHORTCUTS = [
    { test: (e) => e.key === 'PrintScreen', label: 'print-screen' },
    { test: (e) => e.key === 'F12', label: 'f12' },
    { test: (e) => e.ctrlKey && e.shiftKey && ['I', 'J', 'C'].includes(e.key?.toUpperCase()), label: 'devtools' },
    { test: (e) => e.ctrlKey && e.key?.toUpperCase() === 'U', label: 'view-source' },
    { test: (e) => e.ctrlKey && e.key?.toUpperCase() === 'P', label: 'print' },
    { test: (e) => e.ctrlKey && e.key?.toUpperCase() === 'S', label: 'save' },
];

export function useExamIntegrity(options = {}) {
    const {
        /** () => attempt id, so it stays correct across reloads */
        getAttemptId = () => null,
        /** () => the frozen integrity_rules object from the attempt payload */
        getRules = () => null,
        /** Called for every recorded event, terminal or not. */
        onEvent = () => {},
        /** Called when a terminal violation requires the attempt to end. */
        onTerminate = () => {},
    } = options;

    const violations = ref(0);
    const lastViolation = ref(null);
    const fullscreenActive = ref(false);
    const started = ref(false);

    const reported = new Set();
    let blurTimer = null;
    let wasHidden = false;

    const GRACE_MS = 1500;

    function rules() {
        return getRules() || {};
    }

    function attemptId() {
        return getAttemptId();
    }

    function isTerminal(type) {
        return Boolean(rules().terminate_on_violation) && TERMINAL_EVENTS.has(type);
    }

    /**
     * Report an event to the server. Deliberately fire-and-forget: a failed
     * report must never block the UI or stop the attempt from being ended.
     */
    function report(type, metadata = {}) {
        const id = attemptId();
        if (!id) return;

        const payload = {
            event_type: type,
            occurred_at: new Date().toISOString(),
        };

        const entries = Object.entries(metadata);
        if (entries.length) {
            payload.metadata = Object.fromEntries(
                entries.slice(0, 20).map(([k, v]) => [k, String(v).slice(0, 255)])
            );
        }

        student.recordIntegrity(id, payload).catch(() => {
            /* Never surface reporting failures to the student. */
        });
    }

    /**
     * Record an event and act on it. Duplicate bursts of the same event type
     * (blur and visibilitychange often fire together) are collapsed.
     */
    function record(type, metadata = {}) {
        if (reported.has(type)) return;
        reported.add(type);

        violations.value += 1;
        lastViolation.value = type;

        report(type, metadata);
        onEvent(type, metadata);

        if (isTerminal(type)) {
            onTerminate(type);
        }
    }

    // -----------------------------------------------------------------
    // Listeners
    // -----------------------------------------------------------------

    function onVisibilityChange() {
        if (document.hidden) {
            wasHidden = true;
            // The tab/app genuinely went away. Cancel any pending blur grace
            // period: this is the real thing, not a transient blur.
            clearTimeout(blurTimer);
            if (rules().detect_tab_switch) {
                record('TAB_SWITCH', { source: 'visibilitychange' });
            }
        } else if (rules().detect_tab_switch) {
            // Returning is informative for review but is not a violation.
            report('WINDOW_FOCUS', { source: 'visibilitychange' });
        }
    }

    function onBlur() {
        if (!rules().detect_window_blur) return;

        // A blur while the page is still visible is often transient on mobile
        // (on-screen keyboard, a notification shade). Give focus a moment to
        // return before ending someone's exam over it.
        if (!document.hidden) {
            clearTimeout(blurTimer);
            blurTimer = setTimeout(() => {
                if (!document.hidden) {
                    record('WINDOW_BLUR', { source: 'window.blur' });
                }
            }, GRACE_MS);
            return;
        }

        record('WINDOW_BLUR', { source: 'window.blur' });
    }

    function onFocus() {
        clearTimeout(blurTimer);
        if (!document.hidden) {
            report('WINDOW_FOCUS', { source: 'window.focus' });
        }
    }

    function onCopy(e) {
        if (rules().prevent_copy) e.preventDefault();
        record('COPY_ATTEMPT');
    }

    function onPaste(e) {
        if (rules().prevent_paste) e.preventDefault();
        record('PASTE_ATTEMPT');
    }

    function onCut(e) {
        if (rules().prevent_copy) e.preventDefault();
        record('CUT_ATTEMPT');
    }

    function onContextMenu(e) {
        if (rules().prevent_context_menu) e.preventDefault();
        record('CONTEXT_MENU_ATTEMPT');
    }

    function onKeydown(e) {
        if (!rules().detect_keyboard_shortcuts) return;

        const hit = WATCHED_SHORTCUTS.find((s) => s.test(e));
        if (!hit) return;

        // Print Screen cannot be prevented — the OS has already handled it.
        // Record it so the reviewer can see it happened.
        record('KEYBOARD_SHORTCUT', { key: hit.label });
    }

    function onFullscreenChange() {
        fullscreenActive.value = Boolean(document.fullscreenElement);

        if (!rules().fullscreen_required) return;

        if (document.fullscreenElement) {
            report('FULLSCREEN_ENTER');
        } else {
            record('FULLSCREEN_EXIT');
        }
    }

    /**
     * Best effort: the page is being hidden or unloaded, so a normal XHR may
     * not complete. Recorded without waiting for a response.
     */
    function onPageHide() {
        report('WINDOW_BLUR', { source: 'pagehide' });
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    function start() {
        if (started.value) return;
        started.value = true;

        document.addEventListener('visibilitychange', onVisibilityChange);
        document.addEventListener('copy', onCopy);
        document.addEventListener('paste', onPaste);
        document.addEventListener('cut', onCut);
        document.addEventListener('contextmenu', onContextMenu);
        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('keydown', onKeydown);
        window.addEventListener('blur', onBlur);
        window.addEventListener('focus', onFocus);
        window.addEventListener('pagehide', onPageHide);
    }

    function stop() {
        if (!started.value) return;
        started.value = false;

        clearTimeout(blurTimer);

        document.removeEventListener('visibilitychange', onVisibilityChange);
        document.removeEventListener('copy', onCopy);
        document.removeEventListener('paste', onPaste);
        document.removeEventListener('cut', onCut);
        document.removeEventListener('contextmenu', onContextMenu);
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        document.removeEventListener('keydown', onKeydown);
        window.removeEventListener('blur', onBlur);
        window.removeEventListener('focus', onFocus);
        window.removeEventListener('pagehide', onPageHide);
    }

    /**
     * Fullscreen must be entered from a user gesture, so this is exposed for a
     * button rather than called automatically.
     */
    async function requestFullscreen(el) {
        const target = el || document.documentElement;
        try {
            if (!document.fullscreenElement) {
                await target.requestFullscreen();
            }
            return true;
        } catch {
            return false;
        }
    }

    onBeforeUnmount(stop);

    return {
        start,
        stop,
        requestFullscreen,
        violations: readonly(violations),
        lastViolation: readonly(lastViolation),
        fullscreenActive: readonly(fullscreenActive),
    };
}
