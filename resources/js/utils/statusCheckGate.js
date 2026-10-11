/**
 * Decides when an exam heartbeat beat is a full attempt status check
 * (POST /heartbeat, which reads and writes MySQL) and when it is the
 * database-free server-time ping (GET /time).
 *
 * Without saves, a status check goes out once STATUS_CHECK_MIN_GAP_MS has
 * passed since the last one (about every two minutes, see
 * examRequestPacing.js), exactly as before.
 *
 * An answer save that the server confirmed tells the client the same thing a
 * status check would: the attempt was still in progress when the server
 * handled the save, with this deadline. So a confirmed save counts as an
 * observation of the attempt's state, dated when the save was SENT (the
 * server's answer is at least that fresh), and the next status check waits
 * the usual gap from then. The beat that would have been a status check is a
 * time ping instead, so the clock offset and the connection indicator stay as
 * fresh as before.
 *
 * Only a save that actually came back successful counts. A save that is
 * queued, debounced, in flight, superseded, failed, refused (422), sent with
 * keepalive on page exit, or acknowledged for another attempt or another
 * deadline counts for nothing, and the status check is sent on its usual
 * schedule.
 *
 * A save never writes last_heartbeat_at. No rule or screen reads that column
 * today, but so it stays a meaningful record, a real status check is still sent
 * at least once every STATUS_CHECK_MAX_GAP_MS however often the student saves.
 * After a lost connection comes back the next beat is still a real status
 * check (`forced`), as before.
 *
 * The server enforces every deadline and closure on its own: an answer after
 * the deadline or after the attempt was closed is refused whatever the client
 * scheduled, and the per-minute sweep finalizes expired attempts.
 */
import { isAnswerAck } from './answerAck.js';
import { STATUS_CHECK_MIN_GAP_MS } from './examRequestPacing.js';

/** A real status check (which records last_heartbeat_at) goes out at least this often. */
export const STATUS_CHECK_MAX_GAP_MS = 3 * STATUS_CHECK_MIN_GAP_MS;

function sameInstant(a, b) {
    if (!a || !b) return false;
    const x = Date.parse(a);
    const y = Date.parse(b);
    return Number.isFinite(x) && Number.isFinite(y) && x === y;
}

/**
 * True when `ack` is a successful compact answer acknowledgement that proves
 * the given attempt was in progress with the given deadline.
 */
export function isStatusConfirmingAck(ack, { attemptId, expiresAt }) {
    if (!isAnswerAck(ack)) return false;
    if (ack.status !== 'in_progress') return false;
    if (attemptId === null || attemptId === undefined || ack.id === undefined) return false;
    if (Number(ack.id) !== Number(attemptId)) return false;
    return sameInstant(ack.expires_at, expiresAt);
}

export function createStatusCheckGate({
    minGapMs = STATUS_CHECK_MIN_GAP_MS,
    maxGapMs = STATUS_CHECK_MAX_GAP_MS,
} = {}) {
    /** Last time the server's view of the attempt was confirmed (check sent, attempt read, confirmed save sent). */
    let observedAt = null;
    /** Last real status check sent (or attempt read): bounds how long a run of saves can defer one. */
    let checkedAt = null;

    /** The attempt was just read from the server (load, reload, remount). */
    function reset(nowMs) {
        observedAt = nowMs;
        checkedAt = nowMs;
    }

    /**
     * Forget the last observation: the next beat is a status check, and no
     * save counts until one is sent or the attempt is read again.
     */
    function invalidate() {
        observedAt = null;
    }

    /** Whether the beat at `nowMs` must be a full status check. */
    function isDue(nowMs, { forced = false } = {}) {
        if (forced || observedAt === null || checkedAt === null) return true;
        if (nowMs - observedAt >= minGapMs) return true;
        return nowMs - checkedAt >= maxGapMs;
    }

    /** A status check is being sent (counted even if it then fails, as before). */
    function noteStatusCheck(sentAtMs) {
        observedAt = observedAt === null ? sentAtMs : Math.max(observedAt, sentAtMs);
        checkedAt = checkedAt === null ? sentAtMs : Math.max(checkedAt, sentAtMs);
    }

    /**
     * An answer save came back. Counts only when the response confirms the
     * attempt the save was sent for, that attempt is still the one on screen
     * and still open, and the deadline matches the countdown. Returns whether
     * it counted. Never moves the observation time backwards, so a late or
     * out-of-order response cannot undo a newer one.
     */
    function noteSaveResponse(response, { sentAtMs, sentForAttemptId, current }) {
        if (!Number.isFinite(sentAtMs) || observedAt === null) return false;
        if (!current || current.blocked || current.status !== 'in_progress') return false;
        if (Number(current.attemptId) !== Number(sentForAttemptId)) return false;
        if (!isStatusConfirmingAck(response, { attemptId: current.attemptId, expiresAt: current.expiresAt })) return false;
        observedAt = Math.max(observedAt, sentAtMs);
        return true;
    }

    return {
        reset,
        invalidate,
        isDue,
        noteStatusCheck,
        noteSaveResponse,
        observedAt: () => observedAt,
        checkedAt: () => checkedAt,
    };
}
