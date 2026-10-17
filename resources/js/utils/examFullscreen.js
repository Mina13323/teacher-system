/**
 * Fullscreen for the exam start.
 *
 * Browsers grant fullscreen only from the user's own gesture, so the request
 * is made in the click on "Start exam" itself, before any wait or network
 * request (the start pacing can take up to 45 s, longer than the gesture
 * lasts). The SPA then moves to the exam screen in the same document, so the
 * page stays fullscreen.
 *
 * Fullscreen is a browser state, not a security boundary: the student can
 * leave it at any time (Esc, gestures), some devices cannot enter it at all
 * (iPhone Safari), and other tabs and apps stay reachable. Leaving it is
 * recorded by the integrity monitor as configured; it never ends the attempt
 * by itself. A refused or unsupported request is not an error.
 */

/** Requests fullscreen when the exam's frozen rules ask for it. Resolves to whether the page is fullscreen; never throws. */
export function enterFullscreenForStart(rules, doc = typeof document !== 'undefined' ? document : null) {
    if (!rules?.fullscreen_required || !doc) return Promise.resolve(false);
    if (doc.fullscreenElement) return Promise.resolve(true);
    const el = doc.documentElement;
    const request = el?.requestFullscreen || el?.webkitRequestFullscreen;
    if (typeof request !== 'function') return Promise.resolve(false);
    try {
        return Promise.resolve(request.call(el, { navigationUI: 'hide' })).then(() => true, () => false);
    } catch {
        return Promise.resolve(false);
    }
}

/** Leaves fullscreen (used when the start did not lead to the exam screen). */
export function leaveFullscreen(doc = typeof document !== 'undefined' ? document : null) {
    if (!doc?.fullscreenElement || typeof doc.exitFullscreen !== 'function') return;
    try {
        Promise.resolve(doc.exitFullscreen()).catch(() => {});
    } catch {
        /* nothing to undo */
    }
}
