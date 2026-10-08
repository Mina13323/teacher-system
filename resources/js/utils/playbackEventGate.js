/**
 * Decides which video-protection detections are sent to the server.
 *
 * The events are an audit trail (the server records them; nothing is
 * enforced from their count), so one record per departure is enough: the
 * same type is sent at most once per PLAYBACK_EVENT_MIN_GAP_MS, and the
 * DevTools heuristic reports only when it turns on, not on every poll while
 * it stays on. The on-screen notice is not affected.
 */
export const PLAYBACK_EVENT_MIN_GAP_MS = 60_000;

export function createPlaybackEventGate({ minGapMs = PLAYBACK_EVENT_MIN_GAP_MS } = {}) {
    const lastSent = new Map();
    let devtoolsOpen = false;

    return {
        /** True when an event of this type may be sent now (and records it). */
        allow(type, nowMs = Date.now()) {
            const last = lastSent.get(type);
            if (last !== undefined && nowMs - last < minGapMs) return false;
            lastSent.set(type, nowMs);
            return true;
        },
        /** Feed each DevTools poll; true only when it changes from closed to open. */
        devtoolsTurnedOn(open) {
            const turnedOn = open && !devtoolsOpen;
            devtoolsOpen = Boolean(open);
            return turnedOn;
        },
    };
}

/**
 * A window blur that only moved focus into the video's own frame (a click on
 * the embedded player) is not a departure from the page.
 */
export function isBlurIntoPlayer(activeElement, playerElement) {
    if (!activeElement || !playerElement) return false;
    return activeElement.tagName === 'IFRAME' && playerElement.contains(activeElement);
}
