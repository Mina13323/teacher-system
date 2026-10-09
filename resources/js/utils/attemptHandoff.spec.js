import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    HANDOFF_MAX_AGE_MS,
    clearAttemptHandoff,
    handOffAttempt,
    takeHandedOffAttempt,
} from './attemptHandoff';

function snapshot(overrides = {}) {
    return {
        id: 42,
        status: 'in_progress',
        expires_at: '2026-10-08T11:00:00.000Z',
        integrity_rules: { detect_tab_switch: true },
        questions: [{ id: 1, options: [{ id: 11, selected: true }] }],
        ...overrides,
    };
}

/** The API the two pages talk to. */
function fakeApi(startResponse) {
    return {
        startExam: vi.fn().mockResolvedValue(startResponse),
        attempt: vi.fn(async (id) => snapshot({ id: Number(id), source: 'GET' })),
    };
}

/** Mirrors ExamShow.confirmStart's success path. */
async function startFromExamPage(api, { navigationSucceeds = true } = {}) {
    const attempt = await api.startExam(5, { rules_acknowledged: true });
    if (attempt.already_open) {
        clearAttemptHandoff();
    } else {
        handOffAttempt(attempt);
    }
    if (!navigationSucceeds) {
        clearAttemptHandoff();
        return null;
    }
    return String(attempt.id);
}

/** Mirrors ExamTake's load. */
async function loadExamScreen(api, routeId) {
    return takeHandedOffAttempt(routeId) ?? await api.attempt(routeId);
}

beforeEach(() => {
    clearAttemptHandoff();
});

describe('exam start snapshot handoff', () => {
    it('renders the exam from the start response on a normal start, with no GET /attempt', async () => {
        const started = snapshot({ already_open: false });
        const api = fakeApi(started);

        const routeId = await startFromExamPage(api);
        const loaded = await loadExamScreen(api, routeId);

        expect(loaded).toBe(started);
        expect(api.attempt).not.toHaveBeenCalled();
        // Selected answers, deadline and integrity rules come through unchanged.
        expect(loaded.questions[0].options[0].selected).toBe(true);
        expect(loaded.expires_at).toBe(started.expires_at);
        expect(loaded.integrity_rules).toEqual({ detect_tab_switch: true });
    });

    it('reads the attempt from the server on a refresh (snapshot used only once)', async () => {
        const api = fakeApi(snapshot({ already_open: false }));
        const routeId = await startFromExamPage(api);
        await loadExamScreen(api, routeId);

        const reloaded = await loadExamScreen(api, routeId);

        expect(api.attempt).toHaveBeenCalledTimes(1);
        expect(reloaded.source).toBe('GET');
    });

    it('reads the attempt from the server on a deep link', async () => {
        const api = fakeApi(null);

        const loaded = await loadExamScreen(api, '42');

        expect(api.attempt).toHaveBeenCalledWith('42');
        expect(loaded.source).toBe('GET');
    });

    it('reads the attempt from the server when another session already has it open', async () => {
        const api = fakeApi(snapshot({ already_open: true }));

        const routeId = await startFromExamPage(api);
        const loaded = await loadExamScreen(api, routeId);

        expect(api.attempt).toHaveBeenCalledTimes(1);
        expect(loaded.source).toBe('GET');
    });

    it.each([
        ['a compact response without questions', { questions: undefined }],
        ['a missing id', { id: undefined }],
        ['a finished attempt', { status: 'submitted' }],
        ['an expired attempt', { status: 'expired' }],
        ['no deadline', { expires_at: null }],
    ])('falls back to GET for %s', async (_label, overrides) => {
        handOffAttempt(snapshot(overrides));
        const api = fakeApi(null);

        const loaded = await loadExamScreen(api, '42');

        expect(api.attempt).toHaveBeenCalledTimes(1);
        expect(loaded.source).toBe('GET');
    });

    it('falls back to GET when the snapshot is for a different attempt, and discards it', async () => {
        handOffAttempt(snapshot({ id: 41 }));
        const api = fakeApi(null);

        await loadExamScreen(api, '42');
        expect(api.attempt).toHaveBeenCalledWith('42');
        expect(takeHandedOffAttempt('41')).toBeNull();
    });

    it('falls back to GET when the snapshot is stale', () => {
        handOffAttempt(snapshot(), 1_000);
        expect(takeHandedOffAttempt('42', 1_000 + HANDOFF_MAX_AGE_MS + 1)).toBeNull();

        handOffAttempt(snapshot(), 1_000);
        expect(takeHandedOffAttempt('42', 1_000 + HANDOFF_MAX_AGE_MS)).not.toBeNull();
    });

    it('discards the snapshot when navigation to the exam screen fails', async () => {
        const api = fakeApi(snapshot({ already_open: false }));

        await startFromExamPage(api, { navigationSucceeds: false });

        expect(takeHandedOffAttempt('42')).toBeNull();
    });

    it('accepts a paginated questions shape', () => {
        handOffAttempt(snapshot({ questions: { data: [] } }));
        expect(takeHandedOffAttempt(42)).not.toBeNull();
    });
});
