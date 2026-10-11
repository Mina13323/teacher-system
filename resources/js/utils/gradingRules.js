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
