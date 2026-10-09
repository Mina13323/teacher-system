/**
 * Finds text the student typed that the server does not have yet.
 *
 * Essay text was only sent when the student pressed "Save essay", so text
 * typed after the last save was lost on submit, at the deadline and when the
 * tab closed. MCQ explanations are saved after a short pause, but a pause cut
 * short by the deadline had the same problem.
 *
 * Returns `{ [questionId]: payload }` for every essay or explanation whose
 * current text differs from what is already queued (or, if nothing is
 * queued, from the server's copy). Questions with an identical queued or
 * saved copy are left out, so nothing is sent twice.
 */
export function collectUnsavedDrafts(questions, { essayAnswers = {}, mcqExplanations = {}, pending = {}, selectionOf }) {
    const drafts = {};

    for (const q of questions || []) {
        const queued = pending[q.id];

        if (q.question_type === 'essay') {
            if (!(q.id in essayAnswers)) continue;
            const text = essayAnswers[q.id] ?? '';
            const baseline = queued ? (queued.answer_text ?? '') : (q.answer_text ?? '');
            if (text !== baseline) {
                drafts[q.id] = { question_id: q.id, answer_text: text };
            }
            continue;
        }

        if (!q.explanation_enabled || !(q.id in mcqExplanations)) continue;
        const optionIds = queued?.option_ids ?? selectionOf(q);
        if (!optionIds.length) continue;
        const text = mcqExplanations[q.id] ?? '';
        const baseline = queued ? (queued.explanation ?? '') : (q.explanation ?? '');
        if (text !== baseline) {
            drafts[q.id] = { question_id: q.id, option_ids: [...optionIds], explanation: text };
        }
    }

    return drafts;
}
