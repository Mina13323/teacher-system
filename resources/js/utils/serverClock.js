/**
 * Estimates the server clock from the device clock.
 *
 * The exam countdown used to compare the deadline with `Date.now()`, so a
 * device clock that ran fast submitted the exam early (the student lost time)
 * and one that ran slow kept the screen open after the server had closed the
 * attempt. Each sample from a server time response gives
 * `offset = serverTime - (sentAt + receivedAt) / 2`; the sample with the
 * shortest round trip is the most accurate, so that one is used.
 *
 * This only moves the moment the client shows "time is up". Every deadline is
 * still enforced by the server from the attempt row.
 *
 * Once measured, the offset stays right for as long as the device clock keeps
 * running normally, so it does not need a new sample every few seconds.
 * `needsResync()` says when one is needed: no sample yet, or the device clock
 * moved by more than the time that really passed since the last sample (the
 * clock was changed by hand or by a time sync, or the device slept while the
 * page's monotonic clock was paused). Samples from before such a jump are
 * dropped when the next one arrives.
 */
export const SERVER_CLOCK_MAX_SAMPLES = 5;
/** Samples slower than this are too imprecise to trust. */
export const SERVER_CLOCK_MAX_RTT_MS = 10_000;
/** Device clock vs elapsed-time disagreement treated as a clock jump. */
export const SERVER_CLOCK_JUMP_TOLERANCE_MS = 2_000;

const defaultMonotonic = () => (
    typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now()
);

export function createServerClock({ now = () => Date.now(), monotonic = defaultMonotonic } = {}) {
    let samples = [];
    /** Device clock and monotonic clock read when the last sample was accepted. */
    let anchor = null;
    let stale = false;

    function observe(serverTimeMs, sentAtMs, receivedAtMs) {
        if (![serverTimeMs, sentAtMs, receivedAtMs].every(Number.isFinite)) return false;
        const rtt = receivedAtMs - sentAtMs;
        if (rtt < 0 || rtt > SERVER_CLOCK_MAX_RTT_MS) return false;
        if (stale) {
            samples = [];
            stale = false;
        }
        samples.push({ offset: serverTimeMs - (sentAtMs + receivedAtMs) / 2, rtt });
        if (samples.length > SERVER_CLOCK_MAX_SAMPLES) samples = samples.slice(-SERVER_CLOCK_MAX_SAMPLES);
        anchor = { wall: now(), mono: monotonic() };
        return true;
    }

    /** Whether a new server time sample is needed (see the note above). */
    function needsResync() {
        if (!samples.length || anchor === null || stale) return true;
        const drift = (now() - anchor.wall) - (monotonic() - anchor.mono);
        if (Math.abs(drift) > SERVER_CLOCK_JUMP_TOLERANCE_MS) {
            stale = true;
            return true;
        }
        return false;
    }

    /** Milliseconds to add to the device clock to get server time (0 until measured). */
    function offset() {
        if (!samples.length) return 0;
        return Math.round(samples.reduce((best, s) => (s.rtt < best.rtt ? s : best)).offset);
    }

    return {
        observe,
        offset,
        needsResync,
        serverNow: () => now() + offset(),
        sampleCount: () => samples.length,
    };
}
