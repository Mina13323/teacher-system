/**
 * Which grading actions the teacher's attempt screen offers. The server
 * enforces the same rules (422 / 403); these only keep the screen from
 * offering what it would refuse.
 *
 * `status` is the attempt's display status from the staff API: an attempt past
 * its deadline already reads `submitted` (auto-submit exams) or `expired`.
 */

/** Statuses of attempts the student handed in: gradable and publishable. */
export const GRADABLE_STATUSES = ['submitted', 'grading', 'published'];

function statusOf(attempt) {
    return typeof attempt === 'string' ? attempt : attempt?.status;
}

/** A running attempt is never opened for grading (the student is still answering). */
export function canOpenGrading(attempt) {
    const status = statusOf(attempt);
    return Boolean(status) && status !== 'in_progress';
}

/** Grades can be entered and published only for handed-in attempts. */
export function isGradable(attempt) {
    return GRADABLE_STATUSES.includes(statusOf(attempt));
}

/**
 * Reads the points a teacher typed for an essay. Nothing is ever turned into
 * a zero: an empty field is "not graded", and 0 counts only when typed.
 * Returns { ok: true, value } or { ok: false, reason: 'empty' | 'invalid' | 'range' }.
 * Whole points only (the server stores integers).
 */
export function parseAwardedPoints(raw, max) {
    const text = raw === null || raw === undefined ? '' : String(raw).trim();
    if (text === '') return { ok: false, reason: 'empty' };
    if (!/^\d+$/.test(text)) return { ok: false, reason: /^-/.test(text) ? 'range' : 'invalid' };
    const value = Number(text);
    const limit = max === null || max === undefined || max === '' ? NaN : Number(max);
    if (Number.isFinite(limit) && value > limit) return { ok: false, reason: 'range' };
    return { ok: true, value };
}

/** The form value for a stored essay grade: '' while ungraded, never 0 by default. */
export function gradeFieldValue(pointsEarned) {
    return pointsEarned === null || pointsEarned === undefined ? '' : String(pointsEarned);
}

/**
 * Essay questions of a staff attempt that have no grade yet (blank essays
 * included). The server refuses to publish while this is above zero.
 */
export function ungradedEssayCount(questions) {
    if (!Array.isArray(questions)) return 0;
    return questions.filter((q) => (q.question_type || q.type) === 'essay'
        && (q.points_earned === null || q.points_earned === undefined)).length;
}

/** The outstanding-essay count from a refused publication, or null for any other error. */
export function ungradedEssaysFromError(error) {
    const body = error?.data;
    if (error?.status !== 422 || body?.code !== 'essays_ungraded') return null;
    const n = Number(body?.data?.ungraded_essays);
    return Number.isFinite(n) ? n : null;
}
