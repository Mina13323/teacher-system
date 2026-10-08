import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AUTOSAVE_BASE_DELAY_MS,
    AUTOSAVE_MAX_DELAY_MS,
    HEARTBEAT_INTERVAL_MS,
    autosaveDelay,
    autosaveNominalDelay,
    createAutosaveScheduler,
    createHeartbeatScheduler,
    heartbeatInitialDelay,
    heartbeatNextDelay,
} from './examRequestPacing';

const MID = () => 0.5; // no jitter: lands exactly on the nominal delay
const LOW = () => 0; // lower jitter bound
const HIGH = () => 0.999999; // upper jitter bound

/** Lets queued promise callbacks (an awaited beat/flush) run. */
const settle = () => vi.advanceTimersByTimeAsync(0);

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('heartbeat delays', () => {
    it('spreads the first beat uniformly over one full period', () => {
        expect(heartbeatInitialDelay(LOW)).toBe(0);
        expect(heartbeatInitialDelay(MID)).toBe(7_500);
        expect(heartbeatInitialDelay(HIGH)).toBeLessThanOrEqual(HEARTBEAT_INTERVAL_MS);
    });

    it('keeps later beats within 15 s ± 20% with a 15 s mean', () => {
        expect(heartbeatNextDelay(LOW)).toBe(12_000);
        expect(heartbeatNextDelay(MID)).toBe(15_000);
        expect(heartbeatNextDelay(HIGH)).toBe(18_000);
        for (let i = 0; i < 1000; i++) {
            const d = heartbeatNextDelay();
            expect(d).toBeGreaterThanOrEqual(12_000);
            expect(d).toBeLessThanOrEqual(18_000);
        }
    });
});

describe('createHeartbeatScheduler', () => {
    function setup({ hidden = false, random = MID, beat } = {}) {
        const state = { hidden };
        const beatFn = beat ?? vi.fn().mockResolvedValue(undefined);
        const scheduler = createHeartbeatScheduler({ beat: beatFn, random, isHidden: () => state.hidden });
        return { scheduler, beat: beatFn, state };
    }

    it('desynchronizes clients that start at the same moment', async () => {
        const firstBeatAt = [];
        const randoms = [0.05, 0.4, 0.9];
        const t0 = Date.now();
        const schedulers = randoms.map((r, i) => createHeartbeatScheduler({
            beat: () => { firstBeatAt[i] ??= Date.now() - t0; },
            random: () => r,
            isHidden: () => false,
        }));
        schedulers.forEach((s) => s.start());
        await vi.advanceTimersByTimeAsync(HEARTBEAT_INTERVAL_MS);
        expect(firstBeatAt).toEqual([750, 6_000, 13_500]);
        schedulers.forEach((s) => s.stop());
    });

    it('beats on the jittered schedule after a successful beat', async () => {
        const { scheduler, beat } = setup();
        scheduler.start();
        await vi.advanceTimersByTimeAsync(7_499);
        expect(beat).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(beat).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(15_000);
        expect(beat).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(15_000 * 4);
        expect(beat).toHaveBeenCalledTimes(6);
        expect(scheduler.hasPendingTimer()).toBe(true);
        scheduler.stop();
    });

    it('never runs two timers when started repeatedly', async () => {
        const { scheduler, beat } = setup();
        scheduler.start();
        scheduler.start();
        scheduler.start();
        expect(vi.getTimerCount()).toBe(1);
        await vi.advanceTimersByTimeAsync(60_000);
        // 7.5 s, then every 15 s: 7.5, 22.5, 37.5, 52.5
        expect(beat).toHaveBeenCalledTimes(4);
        expect(vi.getTimerCount()).toBe(1);
        scheduler.stop();
    });

    it('a restart during an in-flight beat does not fork a second chain', async () => {
        let release;
        const beat = vi.fn(() => new Promise((resolve) => { release = resolve; }));
        const { scheduler } = setup({ beat });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(7_500);
        expect(beat).toHaveBeenCalledTimes(1);
        scheduler.start(); // e.g. load() after the server finalized the attempt
        release();
        await settle();
        expect(vi.getTimerCount()).toBe(1);
        scheduler.stop();
    });

    it('stop clears the timer and nothing fires afterwards', async () => {
        const { scheduler, beat } = setup();
        scheduler.start();
        scheduler.stop();
        expect(vi.getTimerCount()).toBe(0);
        await vi.advanceTimersByTimeAsync(120_000);
        expect(beat).not.toHaveBeenCalled();
        expect(scheduler.isRunning()).toBe(false);
    });

    it('stop during an in-flight beat prevents any further beat', async () => {
        let release;
        const beat = vi.fn(() => new Promise((resolve) => { release = resolve; }));
        const { scheduler } = setup({ beat });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(7_500);
        scheduler.stop();
        release();
        await settle();
        expect(vi.getTimerCount()).toBe(0);
        await vi.advanceTimersByTimeAsync(120_000);
        expect(beat).toHaveBeenCalledTimes(1);
    });

    it('keeps beating after a failed beat', async () => {
        const beat = vi.fn().mockRejectedValueOnce(new Error('network')).mockResolvedValue(undefined);
        const { scheduler } = setup({ beat });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(7_500 + 15_000);
        expect(beat).toHaveBeenCalledTimes(2);
        scheduler.stop();
    });

    it('pauses while hidden and beats once immediately when visible again', async () => {
        const { scheduler, beat, state } = setup();
        scheduler.start();
        await vi.advanceTimersByTimeAsync(7_500);
        expect(beat).toHaveBeenCalledTimes(1);

        state.hidden = true;
        await vi.advanceTimersByTimeAsync(120_000);
        expect(beat).toHaveBeenCalledTimes(1);
        expect(scheduler.isPaused()).toBe(true);
        expect(vi.getTimerCount()).toBe(0);

        state.hidden = false;
        scheduler.onVisibilityChange();
        await settle();
        expect(beat).toHaveBeenCalledTimes(2);
        expect(scheduler.isPaused()).toBe(false);

        await vi.advanceTimersByTimeAsync(15_000);
        expect(beat).toHaveBeenCalledTimes(3);
        scheduler.stop();
    });

    it('a short hide that ends before the next beat sends nothing extra', async () => {
        const { scheduler, beat, state } = setup();
        scheduler.start();
        await vi.advanceTimersByTimeAsync(1_000);
        state.hidden = true;
        scheduler.onVisibilityChange();
        state.hidden = false;
        scheduler.onVisibilityChange();
        await settle();
        expect(beat).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(6_500);
        expect(beat).toHaveBeenCalledTimes(1);
        scheduler.stop();
    });

    it('ignores visibility changes after stop', async () => {
        const { scheduler, beat, state } = setup({ hidden: true });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(7_500);
        scheduler.stop();
        state.hidden = false;
        scheduler.onVisibilityChange();
        await settle();
        expect(beat).not.toHaveBeenCalled();
    });
});

describe('autosave delays', () => {
    it('backs off 5, 10, 20, 40 s and caps at 60 s', () => {
        expect(autosaveNominalDelay(0)).toBe(5_000);
        expect(autosaveNominalDelay(1)).toBe(5_000);
        expect(autosaveNominalDelay(2)).toBe(10_000);
        expect(autosaveNominalDelay(3)).toBe(20_000);
        expect(autosaveNominalDelay(4)).toBe(40_000);
        expect(autosaveNominalDelay(5)).toBe(60_000);
        expect(autosaveNominalDelay(50)).toBe(AUTOSAVE_MAX_DELAY_MS);
        expect(autosaveNominalDelay(1e6)).toBe(AUTOSAVE_MAX_DELAY_MS);
    });

    it('keeps jitter within ±20% of the nominal delay', () => {
        for (const failures of [0, 1, 2, 3, 4, 5, 9]) {
            const nominal = autosaveNominalDelay(failures);
            expect(autosaveDelay(failures, LOW)).toBe(nominal * 0.8);
            expect(autosaveDelay(failures, HIGH)).toBeLessThanOrEqual(nominal * 1.2);
            for (let i = 0; i < 200; i++) {
                const d = autosaveDelay(failures);
                expect(d).toBeGreaterThanOrEqual(nominal * 0.8);
                expect(d).toBeLessThanOrEqual(nominal * 1.2);
            }
        }
        expect(autosaveDelay(100, HIGH)).toBeLessThanOrEqual(72_000);
    });
});

describe('createAutosaveScheduler', () => {
    function setup({ outcomes = [], pending = { 1: { question_id: 1, option_ids: [3] } } } = {}) {
        const store = { pending };
        const flush = vi.fn(async () => (outcomes.length ? outcomes.shift() : 'ok'));
        const scheduler = createAutosaveScheduler({
            flush,
            hasPending: () => Object.keys(store.pending).length > 0,
            random: MID,
        });
        return { scheduler, flush, store };
    }

    /** Times (ms since start) at which flush() was called. */
    function recordCalls(flush) {
        const t0 = Date.now();
        const at = [];
        flush.mockImplementation(async () => { at.push(Date.now() - t0); return 'failed'; });
        return at;
    }

    it('checks every 5 s while saves succeed, without calling flush when nothing is pending', async () => {
        const { scheduler, flush, store } = setup({ pending: {} });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(30_000);
        expect(flush).not.toHaveBeenCalled();
        store.pending = { 7: { question_id: 7, answer_text: 'x' } };
        await vi.advanceTimersByTimeAsync(5_000);
        expect(flush).toHaveBeenCalledTimes(1);
        scheduler.stop();
    });

    it('retries failures after 5, 10, 20, 40 s and then every 60 s', async () => {
        const { scheduler, flush } = setup();
        const at = recordCalls(flush);
        scheduler.start();
        await vi.advanceTimersByTimeAsync(5_000 + 5_000 + 10_000 + 20_000 + 40_000 + 60_000 + 60_000);
        // gaps: first check 5 s, then 5 (1 failure), 10, 20, 40, 60, 60
        expect(at).toEqual([5_000, 10_000, 20_000, 40_000, 80_000, 140_000, 200_000]);
        expect(scheduler.failures()).toBe(7);
        scheduler.stop();
    });

    it('stays capped no matter how long the outage lasts', async () => {
        const { scheduler, flush } = setup();
        const at = recordCalls(flush);
        scheduler.start();
        await vi.advanceTimersByTimeAsync(60 * 60_000); // one hour
        const gaps = at.slice(1).map((t, i) => t - at[i]);
        expect(Math.max(...gaps)).toBe(AUTOSAVE_MAX_DELAY_MS);
        // ~60 retries in an hour instead of 720 at a fixed 5 s.
        expect(at.length).toBeLessThan(70);
        scheduler.stop();
    });

    it('resets to the 5 s cadence after a successful flush', async () => {
        const { scheduler, flush } = setup({ outcomes: ['failed', 'failed', 'failed', 'ok', 'failed'] });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(5_000 + 5_000 + 10_000 + 20_000); // 4 flushes, last ok
        expect(flush).toHaveBeenCalledTimes(4);
        expect(scheduler.failures()).toBe(0);
        await vi.advanceTimersByTimeAsync(5_000);
        expect(flush).toHaveBeenCalledTimes(5);
        scheduler.stop();
    });

    it('a successful click save resets the backoff and shortens a long wait', async () => {
        const { scheduler, flush } = setup({ outcomes: ['failed', 'failed', 'failed', 'failed', 'failed'] });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(5_000 + 5_000 + 10_000 + 20_000 + 40_000);
        expect(scheduler.failures()).toBe(5); // next retry nominally 60 s away
        scheduler.recordSuccess();
        expect(scheduler.failures()).toBe(0);
        await vi.advanceTimersByTimeAsync(5_000);
        expect(flush).toHaveBeenCalledTimes(6);
        scheduler.stop();
    });

    it('counts a failed click save toward the backoff', async () => {
        const { scheduler, flush } = setup({ pending: {} });
        scheduler.start();
        scheduler.recordFailure();
        scheduler.recordFailure();
        scheduler.recordFailure();
        expect(scheduler.failures()).toBe(3);
        expect(flush).not.toHaveBeenCalled();
        scheduler.stop();
    });

    it('never runs two flushes in parallel, even when the flush is slow', async () => {
        let active = 0;
        let maxActive = 0;
        const flush = vi.fn(async () => {
            active++;
            maxActive = Math.max(maxActive, active);
            await new Promise((resolve) => setTimeout(resolve, 30_000)); // slower than any delay
            active--;
            return 'failed';
        });
        const scheduler = createAutosaveScheduler({ flush, hasPending: () => true, random: MID });
        scheduler.start();
        scheduler.start();
        scheduler.nudge();
        scheduler.recordSuccess();
        await vi.advanceTimersByTimeAsync(10 * 60_000);
        expect(maxActive).toBe(1);
        scheduler.stop();
    });

    it('never drops or rewrites pending answers while retrying', async () => {
        const pending = {
            1: { question_id: 1, option_ids: [3] },
            2: { question_id: 2, answer_text: 'draft essay' },
        };
        const snapshot = JSON.parse(JSON.stringify(pending));
        const { scheduler, store, flush } = setup({ pending });
        recordCalls(flush);
        scheduler.start();
        await vi.advanceTimersByTimeAsync(10 * 60_000);
        expect(flush.mock.calls.length).toBeGreaterThan(5);
        expect(store.pending).toEqual(snapshot);
        scheduler.stop();
    });

    it('online events do not flush immediately or bypass the backoff', async () => {
        const { scheduler, flush } = setup();
        const at = recordCalls(flush);
        scheduler.start();
        await vi.advanceTimersByTimeAsync(5_000 + 5_000 + 10_000 + 20_000); // 4 failures, next in 40 s
        expect(at.length).toBe(4);
        for (let i = 0; i < 20; i++) scheduler.nudge(); // a flapping connection
        await settle();
        expect(at.length).toBe(4);
        expect(vi.getTimerCount()).toBe(1);
        await vi.advanceTimersByTimeAsync(39_999);
        expect(at.length).toBe(4);
        await vi.advanceTimersByTimeAsync(1);
        expect(at.length).toBe(5);
        scheduler.stop();
    });

    it('stop leaves no timer, and a direct flush (submit) is never blocked by backoff', async () => {
        const { scheduler, flush } = setup({ outcomes: ['failed', 'failed', 'failed'] });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(5_000 + 5_000 + 10_000);
        expect(scheduler.failures()).toBe(3);
        // submit()/onTimeUp() stop the chain and call flushPending() themselves.
        scheduler.stop();
        expect(vi.getTimerCount()).toBe(0);
        flush.mockClear();
        await expect(flush()).resolves.toBe('ok');
        expect(flush).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(10 * 60_000);
        expect(flush).toHaveBeenCalledTimes(1);
    });

    it('keeps the failure count across a restart (submit failed, chain resumed)', async () => {
        const { scheduler } = setup({ outcomes: ['failed', 'failed', 'failed'] });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(5_000 + 5_000 + 10_000);
        scheduler.stop();
        scheduler.start();
        expect(scheduler.failures()).toBe(3);
        expect(vi.getTimerCount()).toBe(1);
        scheduler.stop();
    });

    it('a thrown flush counts as a failure and the chain continues', async () => {
        const flush = vi.fn().mockRejectedValueOnce(new Error('boom')).mockResolvedValue('ok');
        const scheduler = createAutosaveScheduler({ flush, hasPending: () => true, random: MID });
        scheduler.start();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_BASE_DELAY_MS);
        expect(scheduler.failures()).toBe(1);
        await vi.advanceTimersByTimeAsync(AUTOSAVE_BASE_DELAY_MS);
        expect(flush).toHaveBeenCalledTimes(2);
        expect(scheduler.failures()).toBe(0);
        scheduler.stop();
    });
});
