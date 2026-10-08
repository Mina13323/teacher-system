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

/**
 * Most beats are a database-free server-time ping. A full attempt status
 * check (which reads and writes MySQL) is sent only when this long has passed
 * since the last one, so about one beat in four (roughly once a minute).
 */
export const STATUS_CHECK_MIN_GAP_MS = 55_000;

/** Whether the next beat should be a full status check instead of a time ping. */
export function shouldCheckStatus(nowMs, lastStatusCheckMs) {
    if (lastStatusCheckMs === null || lastStatusCheckMs === undefined) return true;
    return nowMs - lastStatusCheckMs >= STATUS_CHECK_MIN_GAP_MS;
}

/**
 * Before the deadline every unsaved draft (essay text, MCQ explanations) is
 * sent once, at a random point in this window before time runs out, so the
 * whole class does not send its drafts in the same second. Answers that reach
 * the server after the deadline are refused, so this must happen before it.
 */
export const DEADLINE_FLUSH_WINDOW_MS = [10_000, 30_000];

export function deadlineFlushLeadMs(random = Math.random) {
    const [min, max] = DEADLINE_FLUSH_WINDOW_MS;
    return Math.round(min + (max - min) * random());
}

/**
 * At the deadline the server finalizes the attempt with the deadline itself as
 * the submission time, however late the request arrives, and the per-minute
 * sweep finalizes it even if the request never comes. The client's own
 * "submit" call is therefore spread over this window instead of every student
 * sending it in the same second: 500 students over 50 s is about 10
 * requests a second instead of 60. The screen is locked from the deadline on,
 * so the wait only delays when the student sees the result.
 */
export const TIME_UP_SUBMIT_SPREAD_MS = 50_000;

export function timeUpSubmitDelayMs(random = Math.random) {
    return Math.round(TIME_UP_SUBMIT_SPREAD_MS * random());
}

/**
 * After a submit the unread-notification badge is refreshed after a random
 * delay in this window, not in the same second as the whole class's submits.
 * The result itself is already on screen from the submit response.
 */
export const RESULT_NOTICE_REFRESH_WINDOW_MS = [20_000, 90_000];

export function resultNoticeRefreshDelayMs(random = Math.random) {
    const [min, max] = RESULT_NOTICE_REFRESH_WINDOW_MS;
    return Math.round(min + (max - min) * random());
}

/**
 * Start admission pacing. When a scheduled exam opens, a class presses
 * "start" within seconds of each other, and each start writes the whole
 * attempt snapshot. In the first minutes after the opening time the start
 * request is sent after a random wait of up to START_PACING_MAX_MS ("preparing
 * your exam"), so the starts spread over that window.
 *
 * The wait never costs exam time. The attempt's clock starts when the server
 * creates it, so a wait only matters when the exam window would cut the
 * attempt short: the wait is capped so that the full duration plus a safety
 * margin still fits before the window closes, and it is 0 when it does not.
 * There is no wait outside the opening burst or for exams without a start
 * time.
 */
export const START_PACING_MAX_MS = 45_000;
/** Pacing applies only this long after the exam's opening time. */
export const START_PACING_BURST_WINDOW_MS = 180_000;
/** Kept free before the window closes, beyond the exam's full duration. */
export const START_PACING_SAFETY_MS = 15_000;

export function startPacingDelayMs({ nowMs, startsAt, endsAt = null, durationMinutes = null, random = Math.random }) {
    if (!startsAt || !Number.isFinite(nowMs)) return 0;
    const opensAt = new Date(startsAt).getTime();
    if (!Number.isFinite(opensAt)) return 0;
    const sinceOpen = nowMs - opensAt;
    if (sinceOpen < 0 || sinceOpen > START_PACING_BURST_WINDOW_MS) return 0;

    let max = START_PACING_MAX_MS;
    if (endsAt) {
        const closesAt = new Date(endsAt).getTime();
        const durationMs = Math.max(0, Number(durationMinutes) || 0) * 60_000;
        if (!Number.isFinite(closesAt)) return 0;
        max = Math.min(max, closesAt - nowMs - durationMs - START_PACING_SAFETY_MS);
    }
    if (!(max > 0)) return 0;

    return Math.round(max * random());
}

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
