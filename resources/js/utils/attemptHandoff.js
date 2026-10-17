/**
 * Hands the attempt snapshot returned by POST /start to the exam screen, so
 * the screen does not immediately read the same attempt again.
 *
 * Kept in memory only: a reload, a deep link or a second tab never sees it
 * and reads the attempt from the server as before. It is taken at most once,
 * only for the same attempt id, only while fresh, and only when it looks like
 * a complete in-progress snapshot. Anything else falls back to the GET.
 */
export const HANDOFF_MAX_AGE_MS = 30_000;

let handoff = null;

function isUsableSnapshot(snapshot) {
    if (!snapshot || typeof snapshot !== 'object') return false;
    if (snapshot.id === null || snapshot.id === undefined) return false;
    if (snapshot.status !== 'in_progress') return false;
    if (!snapshot.expires_at) return false;
    const questions = Array.isArray(snapshot.questions) ? snapshot.questions : snapshot.questions?.data;
    return Array.isArray(questions);
}

/** Store the start response for the exam screen. Ignores anything unusable. */
export function handOffAttempt(snapshot, now = Date.now()) {
    handoff = isUsableSnapshot(snapshot) ? { snapshot, at: now } : null;
}

/** Drop any stored snapshot (for example when navigation failed). */
export function clearAttemptHandoff() {
    handoff = null;
}

/**
 * Take the snapshot for `attemptId`, or null. Always clears what was stored,
 * so a snapshot is used once at most.
 */
export function takeHandedOffAttempt(attemptId, now = Date.now()) {
    const entry = handoff;
    handoff = null;
    if (!entry) return null;
    if (String(entry.snapshot.id) !== String(attemptId)) return null;
    if (now - entry.at > HANDOFF_MAX_AGE_MS || now < entry.at) return null;
    return entry.snapshot;
}
