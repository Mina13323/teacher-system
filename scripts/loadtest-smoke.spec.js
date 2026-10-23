import { describe, expect, it, vi } from 'vitest';
import {
    EXAM_TITLE,
    runSmoke,
    SmokeFailure,
    STAGING_URL,
    validateBaseUrl,
    validatePassword,
    validateStudentNumber,
} from './loadtest-smoke-lib.mjs';

const PASSWORD = 'Test-Only#Fixture-Secret-91x';
const EMAIL = 'loadtest.student.0001@staging.maherelmasry.com';
const TOKEN = 'tok_secret_value_123';

/**
 * A scripted fake of the staging API. `override(key, fn)` replaces one route's
 * handler; handlers return [status, jsonBody]. No real network is ever used.
 */
function fakeApi(overrides = {}) {
    const calls = [];
    const answers = {};
    const state = { submitted: false };
    const questions = [
        { id: 11, question_type: 'single_choice', options: [{ id: 101 }, { id: 102 }, { id: 103 }, { id: 104 }] },
        { id: 12, question_type: 'multiple_choice', options: [{ id: 201 }, { id: 202 }, { id: 203 }] },
    ];
    const ok = (data) => [200, { success: true, data }];

    const routes = {
        'POST /auth/login': () => ok({ token: TOKEN, user: { email: EMAIL } }),
        'GET /student/exams': () => ok([{ id: 7, title: EXAM_TITLE }]),
        'GET /student/exams/7': () =>
            ok({
                id: 7,
                title: EXAM_TITLE,
                max_attempts: 1,
                questions_count: 2,
                duration_minutes: 15,
                is_windowed: true,
                starts_at: new Date(Date.now() - 3600_000).toISOString(),
                ends_at: new Date(Date.now() + 86400_000).toISOString(),
                my_attempts: [],
            }),
        'POST /student/exams/7/start': () => [
            201,
            { success: true, data: { id: 55, exam_id: 7, status: 'in_progress', already_open: false, expires_at: new Date(Date.now() + 900_000).toISOString() } },
        ],
        'GET /student/attempts/55': () =>
            ok({
                id: 55,
                status: state.submitted ? 'submitted' : 'in_progress',
                questions: questions.map((q) => ({ ...q, selected_option_ids: answers[q.id] || [] })),
            }),
        'POST /student/attempts/55/answers': (body) => {
            answers[body.question_id] = body.option_ids;
            return ok({ questions: questions.map((q) => ({ id: q.id, selected_option_ids: answers[q.id] || [] })) });
        },
        'POST /student/attempts/55/heartbeat': () => ok({ status: 'in_progress', last_heartbeat_at: new Date().toISOString() }),
        'POST /student/attempts/55/submit': () => {
            state.submitted = true;
            return ok({ attempt_id: 55, status: 'submitted', submitted_at: new Date().toISOString() });
        },
        'POST /auth/logout': () => ok(null),
        ...overrides,
    };

    const fetchImpl = vi.fn(async (url, init) => {
        const path = url.replace(`${STAGING_URL}/api/v1`, '');
        const key = `${init.method} ${path}`;
        calls.push(key);
        const handler = routes[key];
        if (!handler) throw new Error(`unexpected request ${key}`);
        const [status, body] = handler(init.body ? JSON.parse(init.body) : null);
        return { ok: status >= 200 && status < 300, status, json: async () => body };
    });

    return { fetchImpl, calls, answers };
}

async function run(api, extra = {}) {
    const logs = [];
    const promise = runSmoke({ base: STAGING_URL, email: EMAIL, password: PASSWORD, fetchImpl: api.fetchImpl, log: (l) => logs.push(l), ...extra });
    return { promise, logs };
}

async function failure(api) {
    const { promise, logs } = await run(api);
    const error = await promise.then(() => null, (e) => e);
    return { error, logs };
}

describe('validateBaseUrl', () => {
    it('accepts exactly the staging URL, with at most a trailing slash', () => {
        expect(validateBaseUrl('https://staging.maherelmasry.com')).toBe(STAGING_URL);
        expect(validateBaseUrl('https://staging.maherelmasry.com/')).toBe(STAGING_URL);
        expect(validateBaseUrl('  https://staging.maherelmasry.com  ')).toBe(STAGING_URL);
    });

    it.each([
        ['production', 'https://maherelmasry.com'],
        ['production www', 'https://www.maherelmasry.com'],
        ['http', 'http://staging.maherelmasry.com'],
        ['explicit port', 'https://staging.maherelmasry.com:8443'],
        ['default port spelled out', 'https://staging.maherelmasry.com:443'],
        ['a path', 'https://staging.maherelmasry.com/api'],
        ['double slash', 'https://staging.maherelmasry.com//'],
        ['look-alike suffix', 'https://staging.maherelmasry.com.evil.example'],
        ['look-alike prefix', 'https://evilstaging.maherelmasry.com'],
        ['userinfo trick', 'https://staging.maherelmasry.com@evil.example'],
        ['query', 'https://staging.maherelmasry.com/?x=1'],
        ['fragment', 'https://staging.maherelmasry.com/#x'],
        ['other host', 'https://example.com'],
        ['malformed', 'staging.maherelmasry.com'],
        ['garbage', 'not a url'],
        ['empty', ''],
        ['undefined', undefined],
        ['non-string', 42],
    ])('rejects %s', (_label, value) => {
        expect(() => validateBaseUrl(value)).toThrow(SmokeFailure);
    });

    it('sends no request when the URL is invalid (validation precedes any fetch)', () => {
        const fetchImpl = vi.fn();
        expect(() => validateBaseUrl('https://maherelmasry.com')).toThrow(/REFUSING TO RUN/);
        expect(fetchImpl).not.toHaveBeenCalled();
    });
});

describe('input validation', () => {
    it('requires a strong enough password and never has a default', () => {
        expect(() => validatePassword(undefined)).toThrow(/LOADTEST_PASSWORD is required/);
        expect(() => validatePassword('')).toThrow(SmokeFailure);
        expect(() => validatePassword('short')).toThrow(/at least 16/);
        expect(validatePassword(PASSWORD)).toBe(PASSWORD);
    });

    it('does not leak the rejected password in the error', () => {
        try {
            validatePassword('tooShort1!');
        } catch (e) {
            expect(e.message).not.toContain('tooShort1!');
        }
    });

    it('accepts only fixture student numbers', () => {
        expect(validateStudentNumber(undefined)).toBe('0001');
        expect(validateStudentNumber('12')).toBe('0012');
        for (const bad of ['0', '-1', 'abc', '12345', '1.5', '1; rm']) {
            expect(() => validateStudentNumber(bad)).toThrow(SmokeFailure);
        }
    });
});

describe('runSmoke', () => {
    it('passes only after the answer persisted and the attempt is final, then logs out', async () => {
        const api = fakeApi();
        const { promise } = await run(api);
        const result = await promise;

        expect(result).toMatchObject({ attemptId: 55, examId: 7, status: 'submitted' });
        expect(api.calls[api.calls.length - 1]).toBe('POST /auth/logout');
        expect(api.calls.filter((c) => c === 'GET /student/attempts/55')).toHaveLength(2); // open + read-back
    });

    it('never prints the token or the password', async () => {
        const api = fakeApi();
        const { promise, logs } = await run(api);
        await promise;
        expect(logs.join('\n')).not.toContain(TOKEN);
        expect(logs.join('\n')).not.toContain(PASSWORD);

        const failing = fakeApi({ 'POST /student/attempts/55/submit': () => [500, { success: false, message: 'boom' }] });
        const { error, logs: failLogs } = await failure(failing);
        expect(error.message + failLogs.join('\n')).not.toContain(TOKEN);
        expect(error.message + failLogs.join('\n')).not.toContain(PASSWORD);
    });

    it('refuses when the fixture exam is missing and never falls back to another exam', async () => {
        const api = fakeApi({ 'GET /student/exams': () => [200, { success: true, data: [{ id: 99, title: 'A real exam' }] }] });
        const { error } = await failure(api);

        expect(error).toBeInstanceOf(SmokeFailure);
        expect(error.step).toBe('exam list');
        expect(api.calls).not.toContain('GET /student/exams/99');
        expect(api.calls.some((c) => c.includes('/start'))).toBe(false);
    });

    it('refuses when two exams match the fixture title', async () => {
        const api = fakeApi({ 'GET /student/exams': () => [200, { success: true, data: [{ id: 7, title: EXAM_TITLE }, { id: 8, title: EXAM_TITLE }] }] });
        const { error } = await failure(api);
        expect(error.step).toBe('exam list');
        expect(api.calls.some((c) => c.includes('/start'))).toBe(false);
    });

    it('refuses when the exam details do not match the fixture exam', async () => {
        for (const patch of [{ title: 'Renamed' }, { max_attempts: 3 }, { is_windowed: false }, { questions_count: 0 }]) {
            const base = fakeApi();
            const api = fakeApi({
                'GET /student/exams/7': () => [
                    200,
                    { success: true, data: { id: 7, title: EXAM_TITLE, max_attempts: 1, questions_count: 2, duration_minutes: 15, is_windowed: true, starts_at: new Date(Date.now() - 1000).toISOString(), ends_at: new Date(Date.now() + 1e6).toISOString(), my_attempts: [], ...patch } },
                ],
            });
            const { error } = await failure(api);
            expect(error.step).toBe('exam details');
            expect(api.calls.some((c) => c.includes('/start'))).toBe(false);
            expect(base.calls).toEqual([]);
        }
    });

    it('refuses to start when the student already has an attempt', async () => {
        const api = fakeApi({
            'GET /student/exams/7': () => [
                200,
                { success: true, data: { id: 7, title: EXAM_TITLE, max_attempts: 1, questions_count: 2, duration_minutes: 15, is_windowed: true, starts_at: new Date(Date.now() - 1000).toISOString(), ends_at: new Date(Date.now() + 1e6).toISOString(), my_attempts: [{ id: 3 }] } },
            ],
        });
        const { error } = await failure(api);

        expect(error.step).toBe('exam details');
        expect(error.message).toMatch(/already has 1 attempt/);
        expect(api.calls.some((c) => c.includes('/start'))).toBe(false);
        expect(api.calls[api.calls.length - 1]).toBe('POST /auth/logout');
    });

    it('fails when start does not return a fresh in-progress attempt', async () => {
        for (const data of [{ id: 55, exam_id: 7, status: 'in_progress', already_open: true, expires_at: new Date(Date.now() + 1e6).toISOString() }, { id: 55, exam_id: 8, status: 'in_progress', expires_at: new Date(Date.now() + 1e6).toISOString() }, { id: 55, exam_id: 7, status: 'submitted', expires_at: new Date(Date.now() + 1e6).toISOString() }]) {
            const api = fakeApi({ 'POST /student/exams/7/start': () => [201, { success: true, data }] });
            const { error } = await failure(api);
            expect(error.step).toBe('start');
        }
    });

    it('fails on a failed submission and never reports success', async () => {
        const api = fakeApi({ 'POST /student/attempts/55/submit': () => [500, { success: false }] });
        const { error } = await failure(api);
        expect(error).toBeInstanceOf(SmokeFailure);
        expect(error.step).toBe('submit');
        expect(api.calls[api.calls.length - 1]).toBe('POST /auth/logout');
    });

    it.each(['in_progress', 'expired', undefined])('fails when the status after submit is %s', async (status) => {
        const api = fakeApi({ 'POST /student/attempts/55/submit': () => [200, { success: true, data: { attempt_id: 55, status, submitted_at: new Date().toISOString() } }] });
        const { error } = await failure(api);
        expect(error.step).toBe('submit');
    });

    it('fails when the final read-back still says in_progress', async () => {
        let reads = 0;
        const api = fakeApi({
            'GET /student/attempts/55': () => {
                reads += 1;
                return [200, { success: true, data: { id: 55, status: 'in_progress', questions: [{ id: 11, question_type: 'single_choice', options: [{ id: 101 }, { id: 102 }], selected_option_ids: reads > 1 ? [101] : [] }, { id: 12, question_type: 'multiple_choice', options: [{ id: 201 }, { id: 202 }], selected_option_ids: [] }] } }];
            },
        });
        const { error } = await failure(api);
        expect(error.step).toBe('verify');
        expect(error.message).toMatch(/still in progress/);
    });

    it('fails when the saved answer is not echoed as sent', async () => {
        const api = fakeApi({ 'POST /student/attempts/55/answers': () => [200, { success: true, data: { questions: [{ id: 11, selected_option_ids: [999] }] } }] });
        const { error } = await failure(api);
        expect(error.step).toBe('answer');
    });

    it('fails when the persisted answer differs after submit (lost or mismatched answer)', async () => {
        for (const stored of [[], [102]]) {
            let reads = 0;
            const api = fakeApi({
                'GET /student/attempts/55': () => {
                    reads += 1;
                    const submitted = reads > 1;
                    return [200, { success: true, data: { id: 55, status: submitted ? 'submitted' : 'in_progress', questions: [{ id: 11, question_type: 'single_choice', options: [{ id: 101 }, { id: 102 }], selected_option_ids: submitted ? stored : [] }, { id: 12, question_type: 'multiple_choice', options: [{ id: 201 }, { id: 202 }], selected_option_ids: [] }] } }];
                },
            });
            const { error } = await failure(api);
            expect(error.step).toBe('verify');
            expect(error.message).toMatch(/NOT persisted/);
        }
    });

    it('fails when an unexpected extra answer exists after submit', async () => {
        let reads = 0;
        const api = fakeApi({
            'GET /student/attempts/55': () => {
                reads += 1;
                const submitted = reads > 1;
                return [200, { success: true, data: { id: 55, status: submitted ? 'submitted' : 'in_progress', questions: [{ id: 11, question_type: 'single_choice', options: [{ id: 101 }, { id: 102 }], selected_option_ids: submitted ? [101] : [] }, { id: 12, question_type: 'multiple_choice', options: [{ id: 201 }, { id: 202 }], selected_option_ids: submitted ? [201] : [] }] } }];
            },
        });
        const { error } = await failure(api);
        expect(error.step).toBe('verify');
    });

    it('rejects malformed responses instead of assuming a shape', async () => {
        const bad = [
            { 'POST /auth/login': () => [200, { success: true, data: {} }] },
            { 'POST /auth/login': () => [200, { success: true, data: { token: TOKEN, user: { email: 'someone.else@example.com' } } }] },
            { 'GET /student/exams': () => [200, { success: true, data: 'nope' }] },
            { 'GET /student/attempts/55': () => [200, { success: true, data: { id: 55, status: 'in_progress', questions: 'x' } }] },
            { 'POST /student/attempts/55/heartbeat': () => [200, { success: true, data: { status: 'expired' } }] },
        ];
        for (const overrides of bad) {
            const { error } = await failure(fakeApi(overrides));
            expect(error).toBeInstanceOf(SmokeFailure);
        }
    });

    it('treats a network error as a failure, still attempting token cleanup after login', async () => {
        const api = fakeApi({ 'GET /student/exams': () => { throw new Error('socket hang up'); } });
        const base = api.fetchImpl;
        const failing = vi.fn(async (url, init) => {
            if (url.endsWith('/student/exams')) throw new Error('socket hang up');
            return base(url, init);
        });
        const { error } = await failure({ ...api, fetchImpl: failing });
        expect(error).toBeInstanceOf(SmokeFailure);
        expect(failing.mock.calls.at(-1)[0]).toContain('/auth/logout');
    });

    it('warns, without failing, when token cleanup does not succeed', async () => {
        const api = fakeApi({ 'POST /auth/logout': () => [500, { success: false }] });
        const { promise, logs } = await run(api);
        await expect(promise).resolves.toMatchObject({ status: 'submitted' });
        expect(logs.some((l) => l.startsWith('WARN token cleanup'))).toBe(true);
    });
});
