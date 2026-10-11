/**
 * State and rules of the essay-grading workspace, independent of the screen
 * (the view wraps it in `reactive()`; tests drive it with a fake API).
 *
 * Rules it guarantees:
 * - A grade is shown as saved only after the server confirmed it.
 * - A failed save keeps the typed value and never advances; retry is explicit
 *   (no automatic re-send).
 * - Every save carries the grade version the item was loaded with; a 409
 *   conflict keeps the teacher's draft, shows the current server grade, and
 *   is resolved only by an explicit choice (take theirs / save mine again).
 * - An empty points field is never sent; 0 counts only when typed.
 * - Drafts are kept per answer while moving around the queue, so moving to
 *   another answer never loses input; hasUnsaved() drives the leave guard.
 * - A response for an older request never overwrites a newer one.
 */
import { gradeFieldValue, parseAwardedPoints } from './gradingRules.js';

export const DEFAULT_PER_PAGE = 20;
/** Upper bound on publish batches sent in one run (50 attempts each). */
export const MAX_PUBLISH_BATCHES = 40;

export function itemKey(item) {
    return `${item.attempt_id}:${item.question_id}`;
}

function blankDraft(item) {
    return {
        points: gradeFieldValue(item.points_earned),
        feedback: item.feedback ?? '',
        dirty: false,
        state: 'idle', // idle | saving | saved | failed | conflict
        error: null,
        conflict: null,
    };
}

export function createGradingSession({ api, examId, perPage = DEFAULT_PER_PAGE, wrap = (o) => o }) {
    const state = wrap({
        filters: {
            mode: 'question', // question | student
            question_id: null,
            status: 'ungraded',
            publication_status: 'all',
            search: '',
            attempt_id: null,
            student_id: null,
        },
        exam: null,
        items: [],
        meta: null,
        summary: null,
        index: 0,
        drafts: {},
        loading: false,
        loadError: null,
    });

    let requestSeq = 0;

    function current() {
        return state.items[state.index] ?? null;
    }

    function draftFor(item) {
        if (!item) return null;
        const key = itemKey(item);
        if (!state.drafts[key]) state.drafts[key] = blankDraft(item);
        return state.drafts[key];
    }

    function queryFor(page, includeSummary) {
        const f = state.filters;
        const q = { page, per_page: perPage, status: f.status, publication_status: f.publication_status, include_summary: includeSummary ? 1 : 0 };
        if (f.mode === 'question' && f.question_id) q.question_id = f.question_id;
        if (f.search && f.search.trim()) q.search = f.search.trim();
        if (f.attempt_id) q.attempt_id = f.attempt_id;
        if (f.student_id) q.student_id = f.student_id;
        return q;
    }

    /** Loads a page. Resolves to true when applied, false when superseded or failed. */
    async function load({ page = 1, includeSummary = true, index = 0 } = {}) {
        const mine = ++requestSeq;
        state.loading = true;
        state.loadError = null;
        try {
            const res = await api.essayAnswers(examId, queryFor(page, includeSummary));
            if (mine !== requestSeq) return false;
            state.exam = res?.exam ?? state.exam;
            state.items = Array.isArray(res?.answers) ? res.answers : [];
            state.meta = res?.meta ?? null;
            if (res?.summary) {
                state.summary = res.summary;
                // Question mode needs a question: default to the first with work left.
                if (state.filters.mode === 'question' && !state.filters.question_id && res.summary.questions?.length) {
                    const first = res.summary.questions.find((q) => q.ungraded > 0) ?? res.summary.questions[0];
                    state.filters.question_id = first.question_id;
                    return load({ page: 1, includeSummary: false, index });
                }
            }
            // Keep unsaved drafts; refresh the rest from the server.
            for (const item of state.items) {
                const key = itemKey(item);
                if (!state.drafts[key]?.dirty) state.drafts[key] = blankDraft(item);
            }
            state.index = Math.min(Math.max(0, index === 'last' ? state.items.length - 1 : index), Math.max(0, state.items.length - 1));
            return true;
        } catch (e) {
            if (mine !== requestSeq) return false;
            state.loadError = e;
            return false;
        } finally {
            if (mine === requestSeq) state.loading = false;
        }
    }

    function setFilter(name, value) {
        state.filters[name] = value;
        if (name === 'mode' && value === 'student') state.filters.question_id = null;
        return load({ page: 1, includeSummary: name === 'mode' || name === 'publication_status' });
    }

    function setPoints(value) {
        const d = draftFor(current());
        if (!d) return;
        d.points = value === null || value === undefined ? '' : String(value);
        d.dirty = true;
        if (d.state !== 'conflict') d.state = 'idle';
        d.error = null;
    }

    function setFeedback(value) {
        const d = draftFor(current());
        if (!d) return;
        d.feedback = value ?? '';
        d.dirty = true;
        if (d.state !== 'conflict') d.state = 'idle';
    }

    function validation(item = current()) {
        const d = draftFor(item);
        return d ? parseAwardedPoints(d.points, item.max_points) : { ok: false, reason: 'empty' };
    }

    function applySummaryChange(before, after) {
        const s = state.summary;
        if (!s || before === after) return;
        const wasUngraded = before === null || before === undefined;
        const isUngraded = after === null || after === undefined;
        const q = s.questions?.find((x) => x.question_id === current()?.question_id);
        const shift = (obj) => {
            if (!obj) return;
            if (wasUngraded && !isUngraded) { obj.ungraded = Math.max(0, obj.ungraded - 1); obj.graded += 1; }
            if (q === obj && 'graded_zero' in obj) obj.graded_zero += (after === 0 ? 1 : 0) - (before === 0 ? 1 : 0);
        };
        shift(s);
        shift(q);
    }

    /**
     * Saves the current answer. Resolves to 'saved' | 'unchanged' | 'invalid' |
     * 'failed' | 'conflict' | 'busy'.
     */
    async function save() {
        const item = current();
        if (!item) return 'failed';
        const d = draftFor(item);
        if (d.state === 'saving') return 'busy';
        const parsed = validation(item);
        if (!parsed.ok) {
            d.state = 'failed';
            d.error = { kind: 'invalid', reason: parsed.reason };
            return 'invalid';
        }

        d.state = 'saving';
        d.error = null;
        const feedback = d.feedback.trim() === '' ? null : d.feedback;
        try {
            const res = await api.saveEssayGrade(item.attempt_id, {
                question_id: item.question_id,
                awarded_points: parsed.value,
                feedback,
                expected_version: item.grade_version,
            });
            const before = item.points_earned;
            item.points_earned = res.points_earned;
            item.feedback = res.feedback;
            item.graded_at = res.graded_at;
            item.grade_version = res.grade_version;
            item.grading_status = res.points_earned === null ? 'ungraded' : 'graded';
            if (res.attempt) {
                // Every row of the same attempt shares its state.
                for (const other of state.items) {
                    if (other.attempt_id === item.attempt_id) {
                        other.attempt_status = res.attempt.status;
                        other.grades_published = res.attempt.grades_published;
                    }
                }
            }
            applySummaryChange(before, res.points_earned);
            if (res.attempt && state.summary?.attempts && before === null && res.attempt.ungraded_essays === 0 && !res.attempt.grades_published) {
                state.summary.attempts.ready_to_publish += 1;
                state.summary.attempts.awaiting_grading = Math.max(0, state.summary.attempts.awaiting_grading - 1);
            }
            d.points = gradeFieldValue(res.points_earned);
            d.feedback = res.feedback ?? '';
            d.dirty = false;
            d.state = 'saved';
            d.conflict = null;
            return res.saved === false ? 'unchanged' : 'saved';
        } catch (e) {
            if (e?.status === 409 && e?.data?.code === 'grade_conflict') {
                d.state = 'conflict';
                d.conflict = e.data.data?.current ?? null;
                d.error = { kind: 'conflict', message: e.message };
                return 'conflict';
            }
            d.state = 'failed';
            d.error = { kind: e?.status === 422 ? 'rejected' : 'network', message: e?.message ?? '' };
            return 'failed';
        }
    }

    /** Conflict: take the other grader's grade (drops this draft). */
    function acceptServerVersion() {
        const item = current();
        const d = draftFor(item);
        if (!item || !d?.conflict) return;
        Object.assign(item, {
            points_earned: d.conflict.points_earned,
            feedback: d.conflict.feedback,
            graded_at: d.conflict.graded_at,
            grade_version: d.conflict.grade_version,
            grading_status: d.conflict.points_earned === null ? 'ungraded' : 'graded',
        });
        state.drafts[itemKey(item)] = blankDraft(item);
    }

    /** Conflict: keep this draft and allow saving it over the grade now shown. */
    function keepMineAfterConflict() {
        const item = current();
        const d = draftFor(item);
        if (!item || !d?.conflict) return;
        item.grade_version = d.conflict.grade_version;
        d.conflict = null;
        d.state = 'idle';
        d.error = null;
        d.dirty = true;
    }

    async function go(delta) {
        const next = state.index + delta;
        if (next >= 0 && next < state.items.length) {
            state.index = next;
            return true;
        }
        const page = state.meta?.current_page ?? 1;
        if (delta > 0 && state.meta && page < state.meta.last_page) {
            return load({ page: page + 1, includeSummary: false, index: 0 });
        }
        if (delta < 0 && page > 1) {
            return load({ page: page - 1, includeSummary: false, index: 'last' });
        }
        return false;
    }

    const next = () => go(1);
    const previous = () => go(-1);

    async function saveAndNext() {
        const result = await save();
        if (result === 'saved' || result === 'unchanged') await next();
        return result;
    }

    /** Moves to the next answer still without a grade (this page first, then later pages). */
    async function nextUngraded() {
        for (let i = state.index + 1; i < state.items.length; i++) {
            if (state.items[i].points_earned === null) {
                state.index = i;
                return true;
            }
        }
        let page = state.meta?.current_page ?? 1;
        while (state.meta && page < state.meta.last_page) {
            page += 1;
            if (!(await load({ page, includeSummary: false, index: 0 }))) return false;
            const i = state.items.findIndex((it) => it.points_earned === null);
            if (i !== -1) {
                state.index = i;
                return true;
            }
        }
        return false;
    }

    function select(index) {
        if (index >= 0 && index < state.items.length) state.index = index;
    }

    function hasUnsaved() {
        return Object.values(state.drafts).some((d) => d.dirty || d.state === 'saving');
    }

    /**
     * Publishes every fully graded attempt in bounded batches. Stops when the
     * server reports nothing more, when a batch publishes nothing, or after
     * MAX_PUBLISH_BATCHES. Resolves to the summed counts.
     */
    async function publishAll() {
        const totals = { published: 0, blocked_ungraded: 0, skipped: 0, failed: 0, batches: 0, has_more: false };
        for (let batch = 0; batch < MAX_PUBLISH_BATCHES; batch++) {
            const res = await api.publishAllGrades(examId);
            totals.batches += 1;
            for (const k of ['published', 'blocked_ungraded', 'skipped', 'failed']) totals[k] += Number(res?.[k] ?? 0);
            totals.has_more = Boolean(res?.has_more);
            if (state.summary && res?.attempts) state.summary.attempts = res.attempts;
            if (!res?.has_more || !res?.published) break;
        }
        return totals;
    }

    return {
        state,
        current,
        draftFor,
        load,
        setFilter,
        setPoints,
        setFeedback,
        validation,
        save,
        saveAndNext,
        next,
        previous,
        nextUngraded,
        select,
        acceptServerVersion,
        keepMineAfterConflict,
        hasUnsaved,
        publishAll,
    };
}
