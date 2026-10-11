import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { createGradingSession, itemKey, MAX_PUBLISH_BATCHES } from './gradingSession.js';

function row(attempt, question, over = {}) {
    return {
        attempt_id: attempt,
        attempt_number: 1,
        attempt_status: 'grading',
        grades_published: false,
        question_id: question,
        question_text: `Q${question}`,
        max_points: 10,
        position: question,
        student: { id: attempt * 10, name: `Student ${attempt}`, student_code: `C${attempt}` },
        has_answer: true,
        answer_text: 'Answer',
        points_earned: null,
        feedback: null,
        graded_at: null,
        grade_version: `v-${attempt}-${question}-0`,
        grading_status: 'ungraded',
        ...over,
    };
}

const summary = () => ({
    questions: [{ question_id: 7, question_text: 'Q7', max_points: 10, ungraded: 3, graded: 0, graded_zero: 0 }],
    ungraded: 3,
    graded: 0,
    attempts: { ready_to_publish: 0, awaiting_grading: 3, published: 0 },
});

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function apiWith(pages, over = {}) {
    return {
        essayAnswers: vi.fn(async (examId, q) => ({
            exam: { id: examId, title: 'Exam' },
            answers: pages[(q.page ?? 1) - 1] ?? [],
            meta: { current_page: q.page ?? 1, last_page: pages.length, per_page: 2, total: pages.flat().length },
            ...(q.include_summary ? { summary: summary() } : {}),
        })),
        saveEssayGrade: vi.fn(async (attemptId, p) => ({
            attempt_id: attemptId,
            question_id: p.question_id,
            saved: true,
            points_earned: p.awarded_points,
            feedback: p.feedback,
            graded_at: '2026-10-11T10:00:00.000000Z',
            grade_version: `v-${attemptId}-${p.question_id}-${p.awarded_points}`,
            attempt: { id: attemptId, status: 'submitted', score: p.awarded_points, percentage: 50, grades_published: false, ungraded_essays: 0 },
        })),
        publishAllGrades: vi.fn(async () => ({ published: 0, blocked_ungraded: 0, skipped: 0, failed: 0, has_more: false, attempts: {} })),
        ...over,
    };
}

async function started(pages = [[row(1, 7), row(2, 7)], [row(3, 7)]], over = {}) {
    const api = apiWith(pages, over);
    const session = createGradingSession({ api, examId: 5, perPage: 2, wrap: reactive });
    await session.load();
    return { api, session };
}

describe('grading session: loading', () => {
    it('defaults to question mode on the first question with work left, ungraded first', async () => {
        const { api, session } = await started();
        expect(session.state.filters.question_id).toBe(7);
        const last = api.essayAnswers.mock.calls.at(-1)[1];
        expect(last).toMatchObject({ question_id: 7, status: 'ungraded', page: 1, per_page: 2 });
        expect(session.current().attempt_id).toBe(1);
    });

    it('never lets an older response replace a newer one', async () => {
        const slow = deferred();
        const api = apiWith([[row(1, 7)]]);
        const session = createGradingSession({ api, examId: 5, wrap: reactive });
        session.state.filters.question_id = 7;
        api.essayAnswers.mockImplementationOnce(() => slow.promise);
        const older = session.load({ includeSummary: false });
        await session.load({ includeSummary: false });
        slow.resolve({ answers: [row(99, 7)], meta: { current_page: 1, last_page: 1 } });
        await expect(older).resolves.toBe(false);
        expect(session.current().attempt_id).toBe(1);
    });

    it('reports a load failure without dropping what is on screen', async () => {
        const { api, session } = await started();
        api.essayAnswers.mockRejectedValueOnce(Object.assign(new Error('offline'), { isNetwork: true }));
        await expect(session.load({ includeSummary: false })).resolves.toBe(false);
        expect(session.state.loadError.message).toBe('offline');
        expect(session.state.items).toHaveLength(2);
    });
});

describe('grading session: saving', () => {
    it('never sends an empty field, and accepts an explicit zero', async () => {
        const { api, session } = await started();
        await expect(session.save()).resolves.toBe('invalid');
        expect(api.saveEssayGrade).not.toHaveBeenCalled();

        session.setPoints('0');
        await expect(session.save()).resolves.toBe('saved');
        expect(api.saveEssayGrade).toHaveBeenCalledWith(1, { question_id: 7, awarded_points: 0, feedback: null, expected_version: 'v-1-7-0' });
        expect(session.current().points_earned).toBe(0);
    });

    it('shows a grade as saved only after the server confirms it', async () => {
        const pending = deferred();
        const { session } = await started(undefined, { saveEssayGrade: vi.fn(() => pending.promise) });
        session.setPoints('6');
        const saving = session.save();
        expect(session.draftFor(session.current()).state).toBe('saving');
        expect(session.current().points_earned).toBeNull();
        expect(session.hasUnsaved()).toBe(true);
        pending.resolve({ points_earned: 6, feedback: null, graded_at: 'now', grade_version: 'v2', saved: true, attempt: null });
        await saving;
        expect(session.draftFor(session.current()).state).toBe('saved');
        expect(session.current().points_earned).toBe(6);
        expect(session.hasUnsaved()).toBe(false);
    });

    it('keeps the typed value after a failed save, does not advance, and retries only when asked', async () => {
        const saveEssayGrade = vi.fn()
            .mockRejectedValueOnce(Object.assign(new Error('Network Error'), { isNetwork: true }))
            .mockResolvedValueOnce({ points_earned: 8, feedback: 'Fine', graded_at: 'now', grade_version: 'v8', saved: true, attempt: null });
        const { session } = await started(undefined, { saveEssayGrade });
        session.setPoints('8');
        session.setFeedback('Fine');

        await expect(session.saveAndNext()).resolves.toBe('failed');
        expect(session.current().attempt_id).toBe(1);
        const d = session.draftFor(session.current());
        expect(d).toMatchObject({ points: '8', feedback: 'Fine', dirty: true, state: 'failed' });
        expect(d.error.kind).toBe('network');
        expect(saveEssayGrade).toHaveBeenCalledTimes(1);

        await expect(session.save()).resolves.toBe('saved');
        expect(saveEssayGrade).toHaveBeenCalledTimes(2);
    });

    it('a conflict keeps the draft and shows the other grade until the teacher chooses', async () => {
        const conflict = Object.assign(new Error('changed'), {
            status: 409,
            data: { code: 'grade_conflict', data: { current: { points_earned: 3, feedback: 'Other', graded_at: 't', grade_version: 'theirs' } } },
        });
        const saveEssayGrade = vi.fn().mockRejectedValueOnce(conflict).mockImplementation(async (id, p) => ({
            points_earned: p.awarded_points, feedback: p.feedback, graded_at: 't2', grade_version: 'mine2', saved: true, attempt: null,
        }));
        const { session } = await started(undefined, { saveEssayGrade });
        session.setPoints('9');

        await expect(session.saveAndNext()).resolves.toBe('conflict');
        const d = session.draftFor(session.current());
        expect(d.state).toBe('conflict');
        expect(d.points).toBe('9');
        expect(d.conflict.points_earned).toBe(3);
        expect(session.current().points_earned).toBeNull();
        expect(session.current().attempt_id).toBe(1);

        // Saving mine again only after an explicit choice, against their version.
        session.keepMineAfterConflict();
        await session.save();
        expect(saveEssayGrade.mock.calls[1][1].expected_version).toBe('theirs');
        expect(session.current().points_earned).toBe(9);
    });

    it('a conflict can be resolved by taking the other grade', async () => {
        const conflict = Object.assign(new Error('changed'), {
            status: 409,
            data: { code: 'grade_conflict', data: { current: { points_earned: 3, feedback: 'Other', graded_at: 't', grade_version: 'theirs' } } },
        });
        const { session, api } = await started(undefined, { saveEssayGrade: vi.fn().mockRejectedValueOnce(conflict) });
        session.setPoints('9');
        await session.save();
        session.acceptServerVersion();
        expect(session.current()).toMatchObject({ points_earned: 3, feedback: 'Other', grade_version: 'theirs' });
        expect(session.draftFor(session.current())).toMatchObject({ points: '3', feedback: 'Other', dirty: false });
        expect(api.saveEssayGrade).toHaveBeenCalledTimes(1);
    });

    it('a rejected save (422) is shown and kept, not retried', async () => {
        const { session, api } = await started(undefined, {
            saveEssayGrade: vi.fn().mockRejectedValue(Object.assign(new Error('still in progress'), { status: 422 })),
        });
        session.setPoints('4');
        await expect(session.save()).resolves.toBe('failed');
        expect(session.draftFor(session.current()).error).toEqual({ kind: 'rejected', message: 'still in progress' });
        expect(api.saveEssayGrade).toHaveBeenCalledTimes(1);
    });

    it('updates the progress counts from the save response, without reloading', async () => {
        const { session, api } = await started();
        const loads = api.essayAnswers.mock.calls.length;
        session.setPoints('0');
        await session.save();
        expect(session.state.summary.ungraded).toBe(2);
        expect(session.state.summary.graded).toBe(1);
        expect(session.state.summary.questions[0].graded_zero).toBe(1);
        expect(session.state.summary.attempts.ready_to_publish).toBe(1);
        expect(api.essayAnswers.mock.calls.length).toBe(loads);
    });
});

describe('grading session: navigation', () => {
    it('save & next moves on only after a confirmed save, across pages', async () => {
        const { session } = await started();
        session.setPoints('5');
        await session.saveAndNext();
        expect(session.current().attempt_id).toBe(2);
        session.setPoints('5');
        await session.saveAndNext();
        expect(session.state.meta.current_page).toBe(2);
        expect(session.current().attempt_id).toBe(3);
        await session.previous();
        expect(session.state.meta.current_page).toBe(1);
        expect(session.current().attempt_id).toBe(2);
    });

    it('keeps an unsaved draft while moving between answers', async () => {
        const { session } = await started();
        session.setPoints('7');
        await session.next();
        await session.previous();
        expect(session.draftFor(session.current())).toMatchObject({ points: '7', dirty: true });
        expect(session.hasUnsaved()).toBe(true);
    });

    it('keeps unsaved drafts when the page is reloaded', async () => {
        const { session } = await started();
        session.setPoints('4');
        const key = itemKey(session.current());
        await session.load({ includeSummary: false });
        expect(session.state.drafts[key]).toMatchObject({ points: '4', dirty: true });
    });

    it('jumps to the next ungraded answer, loading later pages when needed', async () => {
        const { session } = await started([[row(1, 7), row(2, 7, { points_earned: 4 })], [row(3, 7)]]);
        await expect(session.nextUngraded()).resolves.toBe(true);
        expect(session.current().attempt_id).toBe(3);
        await expect(session.nextUngraded()).resolves.toBe(false);
    });

    it('student mode drops the question filter and lists an attempt\'s essays together', async () => {
        const { api, session } = await started();
        await session.setFilter('mode', 'student');
        const q = api.essayAnswers.mock.calls.at(-1)[1];
        expect(q.question_id).toBeUndefined();
        session.state.filters.attempt_id = 2;
        await session.load({ includeSummary: false });
        expect(api.essayAnswers.mock.calls.at(-1)[1].attempt_id).toBe(2);
    });
});

describe('grading session: publish all', () => {
    it('sends bounded batches until the server has no more', async () => {
        const publishAllGrades = vi.fn()
            .mockResolvedValueOnce({ published: 50, blocked_ungraded: 0, skipped: 0, failed: 0, has_more: true, attempts: { ready_to_publish: 5 } })
            .mockResolvedValueOnce({ published: 5, blocked_ungraded: 1, skipped: 0, failed: 0, has_more: false, attempts: { ready_to_publish: 0 } });
        const { session } = await started(undefined, { publishAllGrades });
        const totals = await session.publishAll();
        expect(totals).toMatchObject({ published: 55, blocked_ungraded: 1, batches: 2, has_more: false });
        expect(session.state.summary.attempts.ready_to_publish).toBe(0);
    });

    it('stops when a batch publishes nothing, and never exceeds the batch cap', async () => {
        const stuck = vi.fn().mockResolvedValue({ published: 0, blocked_ungraded: 50, skipped: 0, failed: 0, has_more: true });
        const { session } = await started(undefined, { publishAllGrades: stuck });
        await session.publishAll();
        expect(stuck).toHaveBeenCalledTimes(1);

        const endless = vi.fn().mockResolvedValue({ published: 1, has_more: true });
        const second = await started(undefined, { publishAllGrades: endless });
        await second.session.publishAll();
        expect(endless).toHaveBeenCalledTimes(MAX_PUBLISH_BATCHES);
    });
});
