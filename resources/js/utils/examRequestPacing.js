/**
 * Client-side pacing for the two periodic exam requests: the liveness
 * heartbeat and the pending-answer autosave flush.
 *
 * Every request opens a fresh MySQL connection on the shared host, so what
 * matters for the database is how many requests arrive in the same second.
 * Students who open an exam together used to stay phase-locked for the whole
 * exam (a fixed 15 s interval), and every client resent failed answers every
 * 5 s during an outage. These helpers spread heartbeats out and back off
 * autosave retries; they never change what is sent or how the server treats it.
 *
 * Timers and randomness are injectable so the schedules can be tested with
 * fake timers.
 */

/** Average heartbeat period. Matches config('integrity.heartbeat_interval_seconds'). */
export const HEARTBEAT_INTERVAL_MS = 15_000;
/** Each beat lands within ±20% of the average (12–18 s), so the mean stays 15 s. */
export const HEARTBEAT_JITTER_RATIO = 0.2;

/** Pending-answer check while saves are succeeding (the previous fixed interval). */
export const AUTOSAVE_BASE_DELAY_MS = 5_000;
/** Longest wait between retries while saves keep failing. */
export const AUTOSAVE_MAX_DELAY_MS = 60_000;
/** Each autosave delay lands within ±20% of its nominal value. */
export const AUTOSAVE_JITTER_RATIO = 0.2;

function jitter(nominalMs, ratio, random) {
    return Math.round(nominalMs * (1 - ratio + 2 * ratio * random()));
}

/** First beat after (re)start: uniform over one full period, so starts that happen together spread out. */
export function heartbeatInitialDelay(random = Math.random) {
    return Math.round(HEARTBEAT_INTERVAL_MS * random());
}

/** Delay between later beats: 15 s ± 20%. */
export function heartbeatNextDelay(random = Math.random) {
    return jitter(HEARTBEAT_INTERVAL_MS, HEARTBEAT_JITTER_RATIO, random);
}

/**
 * Nominal autosave delay after `failures` consecutive failed saves:
 * 0 → 5 s (normal), 1 → 5 s, 2 → 10 s, 3 → 20 s, 4 → 40 s, 5+ → 60 s.
 */
export function autosaveNominalDelay(failures) {
    if (failures <= 1) return AUTOSAVE_BASE_DELAY_MS;
    const exponent = Math.min(failures - 1, 10);
    return Math.min(AUTOSAVE_BASE_DELAY_MS * 2 ** exponent, AUTOSAVE_MAX_DELAY_MS);
}

/** Jittered autosave delay (±20% of the nominal delay). */
export function autosaveDelay(failures, random = Math.random) {
    return jitter(autosaveNominalDelay(failures), AUTOSAVE_JITTER_RATIO, random);
}

const defaultIsHidden = () => typeof document !== 'undefined' && document.visibilityState === 'hidden';

/**
 * Heartbeat on a jittered setTimeout chain (never setInterval), so there is
 * at most one pending timer and one beat in flight.
 *
 * While the page is hidden, a due beat is skipped and the chain pauses. Call
 * `onVisibilityChange()` from a `visibilitychange` listener: when the page is
 * visible again after a skipped beat, one beat is sent at once and the jittered
 * chain resumes. A short hide that ends before the next beat is due changes
 * nothing.
 */
export function createHeartbeatScheduler({
    beat,
    isHidden = defaultIsHidden,
    random = Math.random,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
}) {
    let timer = null;
    let running = false;
    let paused = false;
    let inFlight = false;
    let generation = 0;

    function schedule(delay) {
        if (timer !== null) clearTimer(timer);
        timer = setTimer(tick, delay);
    }

    async function tick() {
        timer = null;
        if (!running) return;
        if (isHidden()) {
            paused = true;
            return;
        }
        const gen = generation;
        inFlight = true;
        try {
            await beat();
        } catch {
            /* the beat callback owns its error handling */
        } finally {
            if (gen === generation) inFlight = false;
        }
        if (gen !== generation || !running) return;
        schedule(heartbeatNextDelay(random));
    }

    function stop() {
        generation++;
        running = false;
        paused = false;
        inFlight = false;
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
    }

    function start() {
        stop();
        running = true;
        schedule(heartbeatInitialDelay(random));
    }

    function onVisibilityChange() {
        if (!running || !paused || inFlight || isHidden()) return;
        paused = false;
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
        tick();
    }

    return {
        start,
        stop,
        onVisibilityChange,
        isRunning: () => running,
        isPaused: () => paused,
        hasPendingTimer: () => timer !== null,
    };
}

/**
 * Autosave flush on a setTimeout chain with exponential backoff.
 *
 * `flush()` must resolve to 'ok' (everything pending was saved), 'failed'
 * (network/server failure; entries stay queued) or anything else (nothing to
 * do, rejected, or another flush was already running), which leaves the
 * backoff unchanged. Saves made outside the chain (a student's click) report
 * their outcome through `recordSuccess()` / `recordFailure()`.
 *
 * The chain only decides WHEN to call `flush()`. It never reads, drops or
 * reorders the pending answers themselves.
 */
export function createAutosaveScheduler({
    flush,
    hasPending,
    random = Math.random,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
}) {
    let timer = null;
    let running = false;
    let inFlight = false;
    let failures = 0;
    let generation = 0;

    function schedule() {
        if (timer !== null) clearTimer(timer);
        timer = setTimer(tick, autosaveDelay(failures, random));
    }

    async function tick() {
        timer = null;
        if (!running) return;
        if (!hasPending()) {
            schedule();
            return;
        }
        const gen = generation;
        inFlight = true;
        let outcome;
        try {
            outcome = await flush();
        } catch {
            outcome = 'failed';
        } finally {
            if (gen === generation) inFlight = false;
        }
        if (gen !== generation || !running) return;
        if (outcome === 'ok') failures = 0;
        else if (outcome === 'failed') failures++;
        schedule();
    }

    function stop() {
        generation++;
        running = false;
        inFlight = false;
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
    }

    /** Starts (or restarts) the chain. The failure count is kept until a save succeeds. */
    function start() {
        stop();
        running = true;
        schedule();
    }

    function recordSuccess() {
        if (failures === 0) return;
        failures = 0;
        // The server is reachable again: come back to the normal 5 s check
        // instead of sitting out a long backoff wait.
        if (running && !inFlight) schedule();
    }

    function recordFailure() {
        failures++;
    }

    /**
     * Called when the browser reports it is back online. It never flushes
     * immediately: it only makes sure a backoff-timed flush is scheduled, so a
     * whole classroom reconnecting at once does not resend together.
     */
    function nudge() {
        if (running && !inFlight && timer === null) schedule();
    }

    return {
        start,
        stop,
        recordSuccess,
        recordFailure,
        nudge,
        failures: () => failures,
        isRunning: () => running,
        hasPendingTimer: () => timer !== null,
    };
}
