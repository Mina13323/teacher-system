import { onBeforeUnmount, readonly, ref } from 'vue';
import { student } from '@/api';

/**
 * Exam proctoring for the student attempt screen — warning-based and honest.
 *
 * This composable is the only place browser-level monitoring lives. It reads
 * the attempt's FROZEN integrity rules (supplied by the server, never chosen
 * by the client), attaches the matching listeners, and reports each OBSERVED
 * event through the existing integrity-events endpoint.
 *
 * Interruption fairness model (P0.5):
 *  - A violation NEVER ends the attempt on the first strike. Every counted
 *    event returns the server's warning state ({ warning_count,
 *    warning_threshold }) and `onWarning` fires so the UI can say "Warning N/M".
 *  - Only the SERVER terminates, once warnings exceed the threshold
 *    (`terminated: true` in the response) — then `onTerminate` fires.
 *  - Nothing is fabricated: a page hide, network loss or app suspension never
 *    reports WINDOW_BLUR. Page-hide only flushes pending work (`onPageHide`).
 *  - Repeated REAL events are all reported (burst-collapsed for ~1.5s where
 *    blur+visibilitychange fire together) so the warning counter can reach the
 *    threshold; the server deduplicates floods.
 *
 * What this can and cannot detect, stated plainly:
 *
 *  CAN report: observed visibility/focus changes, leaving fullscreen, blocked
 *  copy/paste/cut or context-menu actions, and selected keyboard shortcuts
 *  (including Print Screen only when the browser exposes that key event).
 *  A page hide/close is deliberately used only to flush saved work; it is not
 *  recorded as a violation because the browser cannot tell why it happened.
 *
 *  CANNOT detect: an actual screenshot being taken, screen recording, a phone
 *  call, or WHY the tab was left. No browser exposes those reliably. A
 *  recorded event is a suspicious-activity indicator, never proof of intent —
 *  which is why the policy warns and only escalates on repeated confirmed violations.
 */

/** Burst window: blur + visibilitychange for one departure collapse into one report. */
const BURST_MS = 1500;

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
        /** Called for every recorded event. */
        onEvent = () => {},
        /** Called with (warningCount, warningThreshold) after each counted event. */
        onWarning = () => {},
        /** Called ONLY when the server terminates the attempt (threshold exceeded). */
        onTerminate = () => {},
        /** Called on page hide so the host can flush pending answers (never an event). */
        onPageHide = () => {},
    } = options;

    const violations = ref(0);
    const warningCount = ref(0);
    const warningThreshold = ref(null);
    const lastViolation = ref(null);
    const fullscreenActive = ref(false);
    const started = ref(false);

    /** Latest report time per event type — burst collapse only (never once-per-session). */
    const lastReportedAt = new Map();
    let lastDepartureAt = 0;
    let blurTimer = null;
    let pendingWarning = null;

    function rules() {
        return getRules() || {};
    }

    function attemptId() {
        return getAttemptId();
    }

    function effectiveThreshold() {
        if (warningThreshold.value !== null) return warningThreshold.value;
        const fromRules = rules().violation_warning_threshold;
        return typeof fromRules === 'number' ? fromRules : 5;
    }

    function flushPendingWarning() {
        if (pendingWarning) {
            const { count, threshold, remaining } = pendingWarning;
            pendingWarning = null;
            onWarning(count, threshold, remaining);
        }
    }

    /**
     * Report an event to the server and apply the returned warning state.
     * Deliberately fire-and-forget for the UI: a failed report must never block
     * the attempt. Server responses drive ALL termination decisions.
     */
    function report(type, metadata = {}, options = {}) {
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

        const handle = (res) => {
            const data = res?.data ?? res ?? {};
            if (typeof data.warning_count === 'number') {
                warningCount.value = data.warning_count;
            }
            if (typeof data.warning_threshold === 'number') {
                warningThreshold.value = data.warning_threshold;
            }
            // Only fire onWarning when the event was actually counted as a violation
            if (data.counted && typeof data.warning_count === 'number' && data.warning_count > 0 && !data.terminated) {
                const threshold = typeof data.warning_threshold === 'number' ? data.warning_threshold : effectiveThreshold();
                const remaining = typeof data.remaining_warnings === 'number'
                    ? data.remaining_warnings
                    : Math.max(0, threshold - data.warning_count);

                // If the user is currently on another tab, wait until they return to show the warning toast
                if (document.hidden) {
                    pendingWarning = { count: data.warning_count, threshold, remaining };
                } else {
                    onWarning(data.warning_count, threshold, remaining);
                }
            }
            if (data.terminated) {
                onTerminate('THRESHOLD_TERMINATION');
            }
        };

        if (options.keepalive && typeof student.recordIntegrityKeepalive === 'function') {
            // Keepalive cannot read a response — best effort during teardown.
            student.recordIntegrityKeepalive(id, payload);
        } else {
            student.recordIntegrity(id, payload).then(handle).catch(() => {
                /* Never surface reporting failures to the student. */
            });
        }
    }

    /**
     * Record an OBSERVED event and report it. Identical bursts (blur and
     * visibilitychange firing together for one departure) collapse into one
     * report; every later, separately-observed event is reported again so the
     * server-side warning counter can accumulate honestly.
     */
    function record(type, metadata = {}, options = {}) {
        const nowMs = Date.now();

        // Collapse TAB_SWITCH and WINDOW_BLUR firing together for the same departure.
        if (type === 'TAB_SWITCH' || type === 'WINDOW_BLUR') {
            if (nowMs - lastDepartureAt < BURST_MS) return;
            lastDepartureAt = nowMs;
        }

        const last = lastReportedAt.get(type) || 0;
        if (nowMs - last < BURST_MS) return;
        lastReportedAt.set(type, nowMs);

        violations.value += 1;
        lastViolation.value = type;

        report(type, metadata, options);
        onEvent(type, metadata);
    }

    // -----------------------------------------------------------------
    // Listeners — every handler reports only what it actually observed.
    // -----------------------------------------------------------------

    function onVisibilityChange() {
        if (document.hidden) {
            if (rules().detect_tab_switch) {
                // The document went to the background (tab switch, app
                // minimize, phone call, screen off — the browser cannot tell
                // which). Recorded as the visibility event it is, with an
                // honest source label.
                record('TAB_SWITCH', { source: 'visibilitychange' });
            }
        } else {
            // Flush any warning that arrived while tab was hidden so student sees it
            flushPendingWarning();
            if (rules().detect_tab_switch) {
                // Returning is informative for review but is not a violation.
                report('WINDOW_FOCUS', { source: 'visibilitychange' });
            }
        }
    }

    function onBlur() {
        if (!rules().detect_window_blur) return;

        // When document is hidden and detect_tab_switch is enabled,
        // onVisibilityChange already records TAB_SWITCH for this exact departure.
        if (document.hidden && rules().detect_tab_switch) {
            return;
        }

        // A blur while the page is still visible is often transient on mobile
        // (on-screen keyboard, a notification shade, a phone call banner).
        // Give focus a moment to return before recording anything.
        if (!document.hidden) {
            clearTimeout(blurTimer);
            blurTimer = setTimeout(() => {
                if (!document.hidden) {
                    record('WINDOW_BLUR', { source: 'window.blur' });
                }
            }, BURST_MS);
            return;
        }

        record('WINDOW_BLUR', { source: 'window.blur' });
    }

    function onFocus() {
        clearTimeout(blurTimer);
        flushPendingWarning();
        if (!document.hidden) {
            report('WINDOW_FOCUS', { source: 'window.focus' });
        }
    }

    function isEditableTarget(target) {
        const element = target instanceof Element ? target : target?.parentElement;
        return Boolean(element?.closest(
            'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [data-exam-input]'
        ));
    }

    function onCopy(e) {
        // Essay response fields, IME textareas and accessible editable widgets
        // retain normal selection and copy behavior. An unconfigured rule must
        // neither interfere with the browser nor create an integrity event.
        if (isEditableTarget(e.target) || !rules().prevent_copy) return;
        e.preventDefault();
        record('COPY_ATTEMPT');
    }

    function onPaste(e) {
        if (!rules().prevent_paste) return;
        e.preventDefault();
        record('PASTE_ATTEMPT');
    }

    function onCut(e) {
        if (isEditableTarget(e.target) || !rules().prevent_copy) return;
        e.preventDefault();
        record('CUT_ATTEMPT');
    }

    function onContextMenu(e) {
        if (isEditableTarget(e.target) || !rules().prevent_context_menu) return;
        e.preventDefault();
        record('CONTEXT_MENU_ATTEMPT');
    }

    function onKeydown(e) {
        if (e.isComposing || e.keyCode === 229) return;

        const modifier = e.ctrlKey || e.metaKey;
        const key = e.key?.toLowerCase();

        if (modifier && rules().prevent_paste && (key === 'v' || (e.shiftKey && e.key === 'Insert'))) {
            e.preventDefault();
            record('PASTE_ATTEMPT', { source: 'keyboard-shortcut' });
            return;
        }

        // Never interfere with active text editing, IME composition or
        // accessibility shortcuts inside an editable response control.
        if (isEditableTarget(e.target)) return;

        if (modifier && rules().prevent_copy && ['a', 'c', 'x'].includes(key)) {
            e.preventDefault();
            if (key === 'c') record('COPY_ATTEMPT', { source: 'keyboard-shortcut' });
            if (key === 'x') record('CUT_ATTEMPT', { source: 'keyboard-shortcut' });
        }

        if (!rules().detect_keyboard_shortcuts) return;

        const hit = WATCHED_SHORTCUTS.find((shortcut) => shortcut.test(e));
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
     * The page is being hidden or unloaded. This is NOT evidence of anything:
     * no integrity event is recorded. The host gets one chance to flush pending
     * answers (fetch keepalive) so work is not lost.
     */
    function onPageHideEvent() {
        clearTimeout(blurTimer);
        onPageHide();
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
        window.addEventListener('pagehide', onPageHideEvent);
    }

    function stop() {
        if (!started.value) return;
        started.value = false;

        clearTimeout(blurTimer);
        pendingWarning = null;

        document.removeEventListener('visibilitychange', onVisibilityChange);
        document.removeEventListener('copy', onCopy);
        document.removeEventListener('paste', onPaste);
        document.removeEventListener('cut', onCut);
        document.removeEventListener('contextmenu', onContextMenu);
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        document.removeEventListener('keydown', onKeydown);
        window.removeEventListener('blur', onBlur);
        window.removeEventListener('focus', onFocus);
        window.removeEventListener('pagehide', onPageHideEvent);
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
        warningCount: readonly(warningCount),
        warningThreshold: readonly(warningThreshold),
        effectiveThreshold,
        lastViolation: readonly(lastViolation),
        fullscreenActive: readonly(fullscreenActive),
    };
}
