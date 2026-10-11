import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createHeartbeatScheduler, STATUS_CHECK_MIN_GAP_MS, shouldCheckStatus, heartbeatNextDelay } from './examRequestPacing.js';
import { createKeyedQueue } from './keyedQueue.js';
import { createStatusCheckGate, isStatusConfirmingAck, STATUS_CHECK_MAX_GAP_MS } from './statusCheckGate.js';

const DEADLINE = '2026-10-11T12:00:00.000000Z';
const ATTEMPT = 7;

const ack = (over = {}) => ({
    id: ATTEMPT,
    status: 'in_progress',
    expires_at: DEADLINE,
    question: { id: 1, selected_option_id: 11, selected_option_ids: [11], answer_text: null, explanation: null },
    ...over,
});

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

describe('isStatusConfirmingAck', () => {
    const current = { attemptId: ATTEMPT, expiresAt: DEADLINE };

    it('accepts a compact acknowledgement for the current attempt and deadline', () => {
        expect(isStatusConfirmingAck(ack(), current)).toBe(true);
        // Same instant written differently still matches.
        expect(isStatusConfirmingAck(ack({ expires_at: '2026-10-11T12:00:00Z' }), current)).toBe(true);
    });

    it('rejects anything that does not prove this attempt is open with this deadline', () => {
        expect(isStatusConfirmingAck(ack({ id: 8 }), current)).toBe(false);
        expect(isStatusConfirmingAck(ack({ status: 'submitted' }), current)).toBe(false);
        expect(isStatusConfirmingAck(ack({ status: undefined }), current)).toBe(false);
        expect(isStatusConfirmingAck(ack({ expires_at: '2026-10-11T12:05:00Z' }), current)).toBe(false);
        expect(isStatusConfirmingAck(ack({ expires_at: null }), current)).toBe(false);
        expect(isStatusConfirmingAck(ack(), { attemptId: ATTEMPT, expiresAt: null })).toBe(false);
        expect(isStatusConfirmingAck(ack(), { attemptId: null, expiresAt: DEADLINE })).toBe(false);
        expect(isStatusConfirmingAck(null, current)).toBe(false);
        expect(isStatusConfirmingAck(Symbol('superseded'), current)).toBe(false);
        // A full attempt snapshot is not a compact acknowledgement.
        expect(isStatusConfirmingAck({ id: ATTEMPT, status: 'in_progress', expires_at: DEADLINE, questions: [] }, current)).toBe(false);
    });
});

describe('createStatusCheckGate', () => {
    const current = { attemptId: ATTEMPT, status: 'in_progress', expiresAt: DEADLINE, blocked: false };

    it('without saves, follows the existing two-minute schedule exactly', () => {
        const gate = createStatusCheckGate();
        expect(gate.isDue(0)).toBe(true);
        gate.reset(0);
        for (const t of [1, 60_000, STATUS_CHECK_MIN_GAP_MS - 1, STATUS_CHECK_MIN_GAP_MS, 200_000, 400_000]) {
            expect(gate.isDue(t)).toBe(shouldCheckStatus(t, 0));
        }
    });

    it('counts nothing before the attempt was read', () => {
        const gate = createStatusCheckGate();
        expect(gate.noteSaveResponse(ack(), { sentAtMs: 5, sentForAttemptId: ATTEMPT, current })).toBe(false);
        expect(gate.isDue(10)).toBe(true);
    });

    it('a forced check (connection back) is never deferred by a save', () => {
        const gate = createStatusCheckGate();
        gate.reset(0);
        gate.noteSaveResponse(ack(), { sentAtMs: 1_000, sentForAttemptId: ATTEMPT, current });
        expect(gate.isDue(2_000, { forced: true })).toBe(true);
    });

    it('a save never counts once the screen is blocked or the attempt is closed', () => {
        const gate = createStatusCheckGate();
        gate.reset(0);
        expect(gate.noteSaveResponse(ack(), { sentAtMs: 100_000, sentForAttemptId: ATTEMPT, current: { ...current, blocked: true } })).toBe(false);
        expect(gate.noteSaveResponse(ack(), { sentAtMs: 100_000, sentForAttemptId: ATTEMPT, current: { ...current, status: 'submitted' } })).toBe(false);
        expect(gate.isDue(STATUS_CHECK_MIN_GAP_MS)).toBe(true);
    });

    it('after invalidate the next beat is a check and saves count for nothing until it is sent', () => {
        const gate = createStatusCheckGate();
        gate.reset(0);
        gate.invalidate();
        expect(gate.noteSaveResponse(ack(), { sentAtMs: 10, sentForAttemptId: ATTEMPT, current })).toBe(false);
        expect(gate.isDue(20)).toBe(true);
        gate.noteStatusCheck(20);
        expect(gate.isDue(30)).toBe(false);
    });
});

/**
 * Drives the real heartbeat scheduler, gate and per-question save queue the
 * way ExamTake.vue wires them (sendHeartbeat, sendAnswerInOrder,
 * noteConfirmedSave, markConnection), against a fake API.
 */
function createExamClient({ api, random = () => 0.5, current: initial = {} }) {
    const gate = createStatusCheckGate();
    const saves = createKeyedQueue();
    const state = {
        attempt: { id: ATTEMPT, status: 'in_progress', expires_at: DEADLINE },
        countdownExpiresAt: DEADLINE,
        blocked: false,
        connectionLost: false,
        statusSyncWanted: false,
        ...initial,
    };
    const log = [];

    function markConnection(ok) {
        if (state.connectionLost && ok) state.statusSyncWanted = true;
        state.connectionLost = !ok;
    }

    async function beat() {
        if (state.attempt.status !== 'in_progress') {
            scheduler.stop();
            return;
        }
        if (!gate.isDue(Date.now(), { forced: state.statusSyncWanted })) {
            log.push({ t: Date.now(), kind: 'time' });
            try {
                await api.time();
            } catch {
                markConnection(false);
            }
            return;
        }
        gate.noteStatusCheck(Date.now());
        log.push({ t: Date.now(), kind: 'heartbeat' });
        try {
            const res = await api.heartbeat(state.attempt.id);
            markConnection(true);
            state.statusSyncWanted = false;
            if (res.status !== 'in_progress') state.attempt = { ...state.attempt, status: res.status };
        } catch (e) {
            if (e.status === 422) {
                scheduler.stop();
                state.attempt = { ...state.attempt, status: 'expired' };
            } else {
                markConnection(false);
            }
        }
    }

    const scheduler = createHeartbeatScheduler({ beat, isHidden: () => false, random });

    function save(questionId, payload = {}, { attemptId = state.attempt.id } = {}) {
        return saves.run(questionId, async () => {
            const sentAt = Date.now();
            log.push({ t: sentAt, kind: 'answer', questionId, payload });
            const res = await api.answer(attemptId, payload);
            gate.noteSaveResponse(res, {
                sentAtMs: sentAt,
                sentForAttemptId: attemptId,
                current: {
                    attemptId: state.attempt.id,
                    status: state.attempt.status,
                    expiresAt: state.countdownExpiresAt,
                    blocked: state.blocked,
                },
            });
            return res;
        }).then((res) => {
            markConnection(true);
            return res;
        }, (e) => {
            if (e.status !== 422) markConnection(false);
            throw e;
        });
    }

    function load() {
        gate.reset(Date.now());
        scheduler.start();
    }

    return {
        gate,
        state,
        log,
        load,
        save,
        stop: () => scheduler.stop(),
        heartbeats: () => log.filter((e) => e.kind === 'heartbeat').map((e) => e.t),
        pings: () => log.filter((e) => e.kind === 'time').map((e) => e.t),
    };
}

function fakeApi(over = {}) {
    return {
        time: vi.fn(async () => ({ server_time_ms: Date.now() })),
        heartbeat: vi.fn(async () => ({ status: 'in_progress', expires_at: DEADLINE })),
        answer: vi.fn(async (attemptId) => ack({ id: attemptId })),
        ...over,
    };
}

// random 0.5: first beat at 7.5 s, then exactly every 15 s.
// Beats: 7.5, 22.5, ..., 112.5, 127.5 (first >= 115 s), 142.5, ...
const beatAt = (k) => 7_500 + 15_000 * k;
const firstBeatAtOrAfter = (ms) => beatAt(Math.ceil((ms - 7_500) / 15_000));

describe('heartbeat deferral by confirmed answer saves (B1)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(0);
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    it('baseline: without saves the status check goes out at the first beat after two minutes', async () => {
        const client = createExamClient({ api: fakeApi() });
        client.load();
        await vi.advanceTimersByTimeAsync(firstBeatAtOrAfter(STATUS_CHECK_MIN_GAP_MS));
        expect(client.heartbeats()).toEqual([127_500]);
        client.stop();
    });

    it('1. a successful save defers only the status check that falls due within two minutes of it', async () => {
        const api = fakeApi();
        const client = createExamClient({ api });
        client.load();

        await vi.advanceTimersByTimeAsync(100_000);
        await client.save(1, { question_id: 1, option_ids: [11] });

        await vi.advanceTimersByTimeAsync(127_500 - 100_000);
        // The 127.5 s beat was a database-free time ping instead.
        expect(client.heartbeats()).toEqual([]);
        expect(client.pings()).toContain(127_500);

        // The next one goes out two minutes after the save was sent.
        await vi.advanceTimersByTimeAsync(firstBeatAtOrAfter(100_000 + STATUS_CHECK_MIN_GAP_MS) - 127_500);
        expect(client.heartbeats()).toEqual([firstBeatAtOrAfter(215_000)]);
        client.stop();
    });

    it('2. a failed save does not defer the status check', async () => {
        const api = fakeApi({ answer: vi.fn(async () => { throw Object.assign(new Error('Network Error'), { isNetwork: true }); }) });
        const client = createExamClient({ api });
        client.load();
        await vi.advanceTimersByTimeAsync(100_000);
        await expect(client.save(1, { question_id: 1 })).rejects.toThrow();
        await vi.advanceTimersByTimeAsync(27_500);
        expect(client.heartbeats()).toEqual([127_500]);
        client.stop();
    });

    it('2b. a refused (422) save does not defer the status check', async () => {
        const api = fakeApi({ answer: vi.fn(async () => { throw Object.assign(new Error('closed'), { status: 422 }); }) });
        const client = createExamClient({ api });
        client.load();
        await vi.advanceTimersByTimeAsync(100_000);
        await expect(client.save(1, { question_id: 1 })).rejects.toThrow();
        await vi.advanceTimersByTimeAsync(27_500);
        expect(client.heartbeats()).toEqual([127_500]);
        client.stop();
    });

    it('3. a save still in flight does not defer the status check, and its late success cannot undo it', async () => {
        const pending = deferred();
        const api = fakeApi({ answer: vi.fn(() => pending.promise) });
        const client = createExamClient({ api });
        client.load();
        await vi.advanceTimersByTimeAsync(100_000);
        const saving = client.save(1, { question_id: 1 });

        await vi.advanceTimersByTimeAsync(27_500);
        expect(client.heartbeats()).toEqual([127_500]);

        // Response arrives after the check: the save was sent earlier, so it
        // moves nothing and the next check is two minutes after 127.5 s.
        pending.resolve(ack());
        await saving;
        expect(client.gate.observedAt()).toBe(127_500);
        await vi.advanceTimersByTimeAsync(firstBeatAtOrAfter(127_500 + STATUS_CHECK_MIN_GAP_MS) - 127_500);
        expect(client.heartbeats()).toEqual([127_500, 247_500]);
        client.stop();
    });

    it('4. a debounced or queued save does not defer the status check before the server confirms it', async () => {
        const first = deferred();
        const api = fakeApi({
            answer: vi.fn()
                .mockImplementationOnce(() => first.promise)
                .mockImplementation(async (id) => ack({ id })),
        });
        const client = createExamClient({ api });
        client.load();

        await vi.advanceTimersByTimeAsync(100_000);
        // Same question: the second save waits in the queue behind the first.
        const a = client.save(1, { question_id: 1, option_ids: [11] });
        const b = client.save(1, { question_id: 1, option_ids: [12] });
        // A debounced explanation save that fires only after the due beat.
        setTimeout(() => client.save(2, { question_id: 2, explanation: 'x' }), 30_000);

        await vi.advanceTimersByTimeAsync(27_500);
        expect(api.answer).toHaveBeenCalledTimes(1);
        expect(client.heartbeats()).toEqual([127_500]);

        first.resolve(ack());
        await Promise.all([a, b]);
        client.stop();
    });

    it('5. a save confirmed for another attempt, or another deadline, does not defer the status check', async () => {
        const api = fakeApi({
            answer: vi.fn(async (id) => (id === ATTEMPT ? ack({ expires_at: '2026-10-11T13:00:00Z' }) : ack({ id }))),
        });
        const client = createExamClient({ api });
        client.load();
        await vi.advanceTimersByTimeAsync(100_000);
        await client.save(1, { question_id: 1 }, { attemptId: 99 });
        await client.save(1, { question_id: 1 });
        await vi.advanceTimersByTimeAsync(27_500);
        expect(client.heartbeats()).toEqual([127_500]);
        client.stop();
    });

    it('5b. a response naming another attempt than the one it was sent for does not count', async () => {
        const api = fakeApi({ answer: vi.fn(async () => ack({ id: 99 })) });
        const client = createExamClient({ api });
        client.load();
        await vi.advanceTimersByTimeAsync(100_000);
        await client.save(1, { question_id: 1 });
        await vi.advanceTimersByTimeAsync(27_500);
        expect(client.heartbeats()).toEqual([127_500]);
        client.stop();
    });

    it('6. concurrent saves answered out of order keep the newest observation', async () => {
        const early = deferred();
        const late = deferred();
        const api = fakeApi({
            answer: vi.fn()
                .mockImplementationOnce(() => early.promise)
                .mockImplementationOnce(() => late.promise),
        });
        const client = createExamClient({ api });
        client.load();

        await vi.advanceTimersByTimeAsync(50_000);
        const a = client.save(1, { question_id: 1 }); // sent at 50 s
        await vi.advanceTimersByTimeAsync(50_000);
        const b = client.save(2, { question_id: 2 }); // sent at 100 s

        late.resolve(ack());
        await b;
        expect(client.gate.observedAt()).toBe(100_000);
        early.resolve(ack()); // the older save answers last
        await a;
        expect(client.gate.observedAt()).toBe(100_000);

        await vi.advanceTimersByTimeAsync(firstBeatAtOrAfter(215_000) - 100_000);
        expect(client.heartbeats()).toEqual([217_500]);
        client.stop();
    });

    it('7. once the student stops answering, status checks return to the two-minute schedule', async () => {
        const client = createExamClient({ api: fakeApi() });
        client.load();
        await vi.advanceTimersByTimeAsync(20_000);
        await client.save(1, { question_id: 1 });

        await vi.advanceTimersByTimeAsync(10 * 60_000);
        const beats = client.heartbeats();
        // First check two minutes after the last save, then every ~2 minutes.
        expect(beats[0]).toBe(firstBeatAtOrAfter(20_000 + STATUS_CHECK_MIN_GAP_MS));
        for (let i = 1; i < beats.length; i++) {
            expect(beats[i] - beats[i - 1]).toBe(120_000);
        }
        client.stop();
    });

    it('7b. a student who saves continuously still sends a real status check at least every ~6 minutes', async () => {
        const client = createExamClient({ api: fakeApi() });
        client.load();
        const saver = setInterval(() => client.save(1, { question_id: 1 }), 20_000);

        await vi.advanceTimersByTimeAsync(30 * 60_000);
        clearInterval(saver);
        const beats = [0, ...client.heartbeats()];
        expect(beats.length).toBeGreaterThan(4);
        for (let i = 1; i < beats.length; i++) {
            // Never longer than the cap plus one beat period (jitter ≤ 18 s).
            expect(beats[i] - beats[i - 1]).toBeLessThanOrEqual(STATUS_CHECK_MAX_GAP_MS + 18_000);
            expect(beats[i] - beats[i - 1]).toBeGreaterThanOrEqual(STATUS_CHECK_MAX_GAP_MS);
        }
        // Time pings keep going every beat, so the clock and connection
        // indicator are as fresh as before.
        expect(client.heartbeats().length + client.pings().length).toBe(Math.floor((30 * 60_000 - 7_500) / 15_000) + 1);
        client.stop();
    });

    it('8. after a disconnect, the first beat once the connection is back is a real status check', async () => {
        let online = true;
        const offline = () => Object.assign(new Error('Network Error'), { isNetwork: true });
        const api = fakeApi({
            time: vi.fn(async () => { if (!online) throw offline(); return {}; }),
            answer: vi.fn(async (id) => { if (!online) throw offline(); return ack({ id }); }),
        });
        const client = createExamClient({ api });
        client.load();

        await vi.advanceTimersByTimeAsync(30_000);
        online = false;
        await expect(client.save(1, { question_id: 1 })).rejects.toThrow();
        await vi.advanceTimersByTimeAsync(10_000);
        expect(client.state.connectionLost).toBe(true);

        online = true;
        await client.save(1, { question_id: 1 }); // reconnect is noticed by this save
        expect(client.state.statusSyncWanted).toBe(true);
        await vi.advanceTimersByTimeAsync(15_000);
        expect(client.heartbeats()).toEqual([52_500]);
        expect(client.state.statusSyncWanted).toBe(false);
        client.stop();
    });

    it('9. refresh / remount starts a fresh schedule; responses from the old screen change nothing', async () => {
        const late = deferred();
        const api = fakeApi({ answer: vi.fn().mockImplementationOnce(() => late.promise).mockImplementation(async (id) => ack({ id })) });
        const oldScreen = createExamClient({ api });
        oldScreen.load();
        await vi.advanceTimersByTimeAsync(100_000);
        const pending = oldScreen.save(1, { question_id: 1 });
        oldScreen.stop(); // unmount

        const newScreen = createExamClient({ api });
        newScreen.load(); // reads the attempt at 100 s
        late.resolve(ack());
        await pending;
        expect(newScreen.gate.observedAt()).toBe(100_000);

        await vi.advanceTimersByTimeAsync(firstBeatAtOrAfter(STATUS_CHECK_MIN_GAP_MS) + 1);
        expect(oldScreen.heartbeats()).toEqual([]);
        // Its schedule starts at its own load (100 s): first check two minutes later.
        expect(newScreen.heartbeats()).toEqual([100_000 + 127_500]);
        newScreen.stop();
    });

    it('9b. a reload in the same screen ignores a save sent before it', async () => {
        const late = deferred();
        const api = fakeApi({ answer: vi.fn(() => late.promise) });
        const client = createExamClient({ api });
        client.load();
        await vi.advanceTimersByTimeAsync(50_000);
        const pending = client.save(1, { question_id: 1 });
        await vi.advanceTimersByTimeAsync(10_000);
        client.load(); // reload at 60 s
        late.resolve(ack());
        await pending;
        expect(client.gate.observedAt()).toBe(60_000);
        client.stop();
    });

    it('10. a closed attempt stops the beats; a save refused at the deadline never defers a check', async () => {
        let closed = false;
        const api = fakeApi({
            heartbeat: vi.fn(async () => { if (closed) throw Object.assign(new Error('closed'), { status: 422 }); return { status: 'in_progress' }; }),
            answer: vi.fn(async (id) => { if (closed) throw Object.assign(new Error('closed'), { status: 422 }); return ack({ id }); }),
        });
        const client = createExamClient({ api });
        client.load();
        await vi.advanceTimersByTimeAsync(100_000);
        await client.save(1, { question_id: 1 });

        closed = true; // teacher / integrity / sweep closes the attempt on the server
        await expect(client.save(1, { question_id: 1 })).rejects.toThrow('closed');
        await vi.advanceTimersByTimeAsync(firstBeatAtOrAfter(215_000) - 100_000);
        expect(client.heartbeats()).toEqual([217_500]);
        expect(client.state.attempt.status).toBe('expired');
        const after = api.heartbeat.mock.calls.length + api.time.mock.calls.length;
        await vi.advanceTimersByTimeAsync(5 * 60_000);
        expect(api.heartbeat.mock.calls.length + api.time.mock.calls.length).toBe(after);
    });

    it('10b. the time-up offline path forces a real check that no save can defer', async () => {
        const client = createExamClient({ api: fakeApi() });
        client.load();
        await vi.advanceTimersByTimeAsync(20_000);
        client.gate.invalidate(); // onTimeUp network failure in ExamTake.vue
        await client.save(1, { question_id: 1 });
        await vi.advanceTimersByTimeAsync(15_000);
        expect(client.heartbeats()).toEqual([22_500]);
        client.stop();
    });

    it('11. every answer is sent at once and in order; deferral never holds back or drops a save', async () => {
        const api = fakeApi();
        const client = createExamClient({ api });
        client.load();
        await vi.advanceTimersByTimeAsync(10_000);
        const payloads = [1, 2, 3].map((n) => ({ question_id: 1, option_ids: [10 + n] }));
        const saved = payloads.map((p) => client.save(1, p));
        await Promise.all(saved);
        expect(api.answer.mock.calls.map((c) => c[1])).toEqual(payloads);
        expect(client.log.filter((e) => e.kind === 'answer').every((e) => e.t === 10_000)).toBe(true);
        client.stop();
    });
});

describe('B1 request estimate (simulation, not a measurement)', () => {
    function simulate({ minutes, saveEveryMs }) {
        const gate = createStatusCheckGate();
        gate.reset(0);
        let random = 0;
        const rand = () => {
            random = (random + 0.37) % 1;
            return random;
        };
        const current = { attemptId: ATTEMPT, status: 'in_progress', expiresAt: DEADLINE, blocked: false };
        let now = 0;
        let nextSave = saveEveryMs ?? Infinity;
        let checks = 0;
        let pings = 0;
        while (now < minutes * 60_000) {
            now += heartbeatNextDelay(rand);
            while (nextSave <= now) {
                gate.noteSaveResponse(ack(), { sentAtMs: nextSave, sentForAttemptId: ATTEMPT, current });
                nextSave += saveEveryMs;
            }
            if (gate.isDue(now)) {
                gate.noteStatusCheck(now);
                checks++;
            } else {
                pings++;
            }
        }
        return { checks, pings };
    }

    it('cuts status checks to about a third for a student who saves at least every two minutes', () => {
        const idle = simulate({ minutes: 60 });
        const active = simulate({ minutes: 60, saveEveryMs: 45_000 });
        // About 28 database heartbeats an hour before, about 10 now.
        expect(idle.checks).toBeGreaterThanOrEqual(26);
        expect(idle.checks).toBeLessThanOrEqual(31);
        expect(active.checks).toBeGreaterThanOrEqual(9);
        expect(active.checks).toBeLessThanOrEqual(11);
        // Same number of beats: deferred checks become time pings.
        expect(active.checks + active.pings).toBe(idle.checks + idle.pings);
    });

    it('changes nothing for a student who saves less often than every two minutes', () => {
        const idle = simulate({ minutes: 60 });
        const sparse = simulate({ minutes: 60, saveEveryMs: 10 * 60_000 });
        expect(sparse.checks).toBeGreaterThanOrEqual(idle.checks - 6);
    });
});
