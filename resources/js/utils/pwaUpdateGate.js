/**
 * Spacing for service-worker update checks.
 *
 * Each check re-downloads /sw.js (served no-cache). They used to run on every
 * window focus, visibility change and page show, which during an exam means
 * one or two requests each time the student comes back to the tab. A new
 * version cannot appear more often than a deployment, and taking it is
 * user-confirmed anyway, so a check triggered by focus/visibility/pageshow is
 * skipped when one ran less than UPDATE_CHECK_MIN_GAP_MS ago. The check on
 * registration, on coming back online and the 30-minute timer still run.
 */
export const UPDATE_CHECK_MIN_GAP_MS = 10 * 60 * 1000;

export function createUpdateCheckGate({ minGapMs = UPDATE_CHECK_MIN_GAP_MS, now = () => Date.now() } = {}) {
    let lastCheckAt = null;
    return {
        /** True when a check should run now; `forced` checks always run. Records the check. */
        allow({ forced = false } = {}) {
            const t = now();
            if (!forced && lastCheckAt !== null && t - lastCheckAt < minGapMs) return false;
            lastCheckAt = t;
            return true;
        },
    };
}
