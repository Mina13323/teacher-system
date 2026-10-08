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
 */
export const SERVER_CLOCK_MAX_SAMPLES = 5;
/** Samples slower than this are too imprecise to trust. */
export const SERVER_CLOCK_MAX_RTT_MS = 10_000;

export function createServerClock({ now = () => Date.now() } = {}) {
    let samples = [];

    function observe(serverTimeMs, sentAtMs, receivedAtMs) {
        if (![serverTimeMs, sentAtMs, receivedAtMs].every(Number.isFinite)) return false;
        const rtt = receivedAtMs - sentAtMs;
        if (rtt < 0 || rtt > SERVER_CLOCK_MAX_RTT_MS) return false;
        samples.push({ offset: serverTimeMs - (sentAtMs + receivedAtMs) / 2, rtt });
        if (samples.length > SERVER_CLOCK_MAX_SAMPLES) samples = samples.slice(-SERVER_CLOCK_MAX_SAMPLES);
        return true;
    }

    /** Milliseconds to add to the device clock to get server time (0 until measured). */
    function offset() {
        if (!samples.length) return 0;
        return Math.round(samples.reduce((best, s) => (s.rtt < best.rtt ? s : best)).offset);
    }

    return {
        observe,
        offset,
        serverNow: () => now() + offset(),
        sampleCount: () => samples.length,
    };
}
