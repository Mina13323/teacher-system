import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    ABORT_ON_ANY,
    closedTooEarly,
    criticalSignal,
    examDurationProblem,
    FINAL_STATUSES,
    mismatchedAnswers,
    startedAttemptProblem,
    submitProblem,
} from './guards.js';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

describe('criticalSignal', () => {
    it.each([200, 201, 204, 302, 404, 409, 422, 429])('treats HTTP %i as non-critical', (status) => {
        expect(criticalSignal({ status, body: '{}', endpoint: 'answer' })).toBeNull();
    });

    it.each([500, 502, 503, 504, 508])('aborts on HTTP %i', (status) => {
        expect(criticalSignal({ status, body: '', endpoint: 'start' })).toMatch(new RegExp(`HTTP ${status} on start`));
    });

    it('aborts on a transport failure (status 0)', () => {
        expect(criticalSignal({ status: 0, body: null, endpoint: 'login' })).toMatch(/transport failure/);
    });

    it('aborts on an unexpected 401, with or without a token', () => {
        expect(criticalSignal({ status: 401, body: '{}', endpoint: 'heartbeat' })).toMatch(/401 on heartbeat/);
    });

    it('recognises SQLSTATE 2002 / connection refused / service busy in a 5xx body', () => {
        for (const body of [
            '{"message":"SQLSTATE[HY000] [2002] Operation not permitted"}',
            'SQLSTATE[HY000] [2002] Connection refused',
            '{"error":"service_busy"}',
            'DB_CONNECT_FINAL',
        ]) {
            expect(criticalSignal({ status: 503, body, endpoint: 'answer' })).toMatch(/database connection failure/);
        }
    });

    it('recognises SQLSTATE 2002 even when the status is not 5xx', () => {
        expect(criticalSignal({ status: 200, body: 'x SQLSTATE[HY000] [2002] x', endpoint: 'exams' })).toMatch(/SQLSTATE 2002/);
    });

    it('never echoes response bodies or tokens in the reason', () => {
        const reason = criticalSignal({ status: 500, body: 'secret-token-abc SQLSTATE[HY000] [2002]', endpoint: 'login' });
        expect(reason).not.toContain('secret-token-abc');
    });
});

describe('startedAttemptProblem', () => {
    const good = { id: 5, exam_id: 7, status: 'in_progress', attempt_number: 1, already_open: false, expires_at: '2099-01-01T00:00:00Z', questions: [1, 2] };

    it('accepts a fresh open attempt', () => {
        expect(startedAttemptProblem(good, { examId: 7, expectedQuestions: 2, nowMs: Date.now() })).toBeNull();
    });

    it.each([
        ['no attempt', null],
        ['no id', { ...good, id: undefined }],
        ['other exam', { ...good, exam_id: 8 }],
        ['not in progress', { ...good, status: 'submitted' }],
        ['duplicate / already open', { ...good, already_open: true }],
        ['second attempt number', { ...good, attempt_number: 2 }],
        ['no deadline', { ...good, expires_at: undefined }],
        ['past deadline', { ...good, expires_at: '2000-01-01T00:00:00Z' }],
        ['wrong question count', { ...good, questions: [1] }],
    ])('flags %s', (_label, attempt) => {
        expect(startedAttemptProblem(attempt, { examId: 7, expectedQuestions: 2, nowMs: Date.now() })).toEqual(expect.any(String));
    });
});

describe('mismatchedAnswers', () => {
    it('is empty when every acknowledged answer is stored, ignoring order', () => {
        expect(mismatchedAnswers({ 1: [3, 1], 2: [5] }, { 1: [1, 3], 2: [5] })).toEqual([]);
    });

    it('reports lost and changed answers', () => {
        expect(mismatchedAnswers({ 1: [1], 2: [5], 3: [9] }, { 1: [1], 2: [6] })).toEqual(['2', '3']);
    });
});

describe('examDurationProblem', () => {
    const base = { examMinutes: 15, openAtSeconds: 465, startPacingMaxSeconds: 45, submitSpreadSeconds: 50, unreadMaxSeconds: 180, slackSeconds: 120 };
    const maxFor = (minutes) => Math.ceil(465 + 45 + minutes * 60 + 50 + 180 + 120);

    it('accepts a matching exam', () => {
        expect(examDurationProblem({ ...base, durationMinutes: 15, maxDurationSeconds: maxFor(15) })).toBeNull();
        expect(examDurationProblem({ ...base, durationMinutes: 10, maxDurationSeconds: maxFor(15) })).toBeNull();
    });

    it('refuses an exam longer than EXAM_MINUTES, naming both numbers', () => {
        const problem = examDurationProblem({ ...base, durationMinutes: 60, maxDurationSeconds: maxFor(15) });
        expect(problem).toMatch(/60 min/);
        expect(problem).toMatch(/EXAM_MINUTES=15/);
    });

    it('refuses when the scenario length cannot cover one student', () => {
        expect(examDurationProblem({ ...base, durationMinutes: 15, maxDurationSeconds: maxFor(15) - 1 })).toMatch(/allows/);
    });

    it.each([undefined, null, 0, -5, NaN, '15'])('refuses an unusable duration %s', (durationMinutes) => {
        expect(examDurationProblem({ ...base, durationMinutes, maxDurationSeconds: maxFor(15) })).toMatch(/usable duration/);
    });
});

describe('submitProblem and closedTooEarly', () => {
    it('accepts a final status or an already-finalized 422', () => {
        for (const status of FINAL_STATUSES) expect(submitProblem(200, { attempt_id: 3, status }, 3)).toBeNull();
        expect(submitProblem(422, null, 3)).toBeNull();
    });

    it('flags a non-final, mismatched or failed submit', () => {
        expect(submitProblem(200, { attempt_id: 3, status: 'in_progress' }, 3)).toMatch(/in_progress/);
        expect(submitProblem(200, { attempt_id: 4, status: 'submitted' }, 3)).toMatch(/different attempt/);
        expect(submitProblem(200, null, 3)).toEqual(expect.any(String));
        expect(submitProblem(403, null, 3)).toMatch(/HTTP 403/);
    });

    it('treats a close long before the deadline as inconsistent', () => {
        expect(closedTooEarly(120_000, 30_000)).toBe(true);
        expect(closedTooEarly(5_000, 30_000)).toBe(false);
        expect(closedTooEarly(-1_000, 30_000)).toBe(false);
    });
});

describe('k6 wiring (static checks of the scripts that k6 itself runs)', () => {
    const config = read('../k6-config.js');
    const realistic = read('../exam-realistic.js');

    it('keeps the strict staging guard and the per-request re-check', () => {
        expect(config).toContain("export const REQUIRED_BASE_URL = 'https://staging.maherelmasry.com';");
        expect(config).toContain('// Defence in depth: re-check the target before every request.');
        expect(config).toContain('assertStagingUrl(BASE_URL);');
    });

    it('aborts the whole run through k6 exec.test.abort, not just a failed check', () => {
        expect(config).toContain("import exec from 'k6/execution';");
        expect(config).toContain('exec.test.abort(');
        expect(config).toContain('criticalSignal({ status: res.status, body: res.body, endpoint })');
        expect(config).toContain('if (critical) abortRun(critical);');
    });

    it('trips count thresholds with abortOnFail for every critical counter', () => {
        expect(ABORT_ON_ANY).toEqual({ threshold: 'count==0', abortOnFail: true, delayAbortEval: '0s' });
        expect(config).toContain('critical_failures: [ABORT_ON_ANY]');
        expect(config).toContain('fixture_not_clean: [ABORT_ON_ANY]');
        expect(realistic).toContain('answers_lost: [ABORT_ON_ANY]');
        expect(realistic).toContain('submit_not_final: [ABORT_ON_ANY]');
        expect(realistic).toContain("service_busy_503: [{ threshold: 'count==0', abortOnFail: true");
    });

    it('keeps the percentage thresholds as well (not replaced)', () => {
        expect(config).toContain("http_req_failed: ['rate<0.02']");
        expect(config).toContain("rate<0.005");
        expect(config).toContain("flow_failed: ['rate<0.05']");
    });

    it('requires the fixture password and has no public default', () => {
        expect(config).not.toContain('LoadTest#Staging-2026');
        expect(config).toContain('LOADTEST_PASSWORD is required');
    });

    it('validates the real exam duration before starting an attempt', () => {
        const durationCheck = realistic.indexOf('examDurationProblem({');
        const startCall = realistic.indexOf('/start`');
        expect(durationCheck).toBeGreaterThan(-1);
        expect(durationCheck).toBeLessThan(startCall);
        expect(realistic).toContain('details.detail.duration_minutes');
        expect(realistic).toContain('exam duration mismatch');
    });

    it('aborts on dirty fixtures, duplicate attempts, lost answers and non-final submits', () => {
        expect(config).toContain("abortRun('fixture student already has an attempt");
        expect(realistic).toContain('startedAttemptProblem(attempt');
        expect(realistic).toContain('acknowledged answer(s) were not stored as acknowledged');
        expect(realistic).toContain('submit not final');
        expect(realistic).toContain('failIfClosedEarly(');
    });

    it('does not alter the exam flow order or the realistic pacing constants', () => {
        for (const needle of ['const PING_MIN_S = 12;', 'const PING_MAX_S = 18;', 'const STATUS_GAP_S = 115;', "num('START_PACING_MAX_S', 45)", 'const SUBMIT_SPREAD_S = 50;', 'sleep(between(0, SUBMIT_SPREAD_S));']) {
            expect(realistic).toContain(needle);
        }
        const order = ['login(student.email)', 'getDashboard(token', 'findExam(token', 'getExamDetails(token', '/start`', '/submit`', "'result'", "'unread'"].map((s) => realistic.indexOf(s));
        expect(order.every((i) => i > -1)).toBe(true);
        expect([...order].sort((a, b) => a - b)).toEqual(order);
    });
});
