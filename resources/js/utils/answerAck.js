/**
 * Compact answer acknowledgements.
 *
 * An answer save asks the server for a short acknowledgement of the saved
 * question only (`compact_response`), instead of the whole attempt snapshot
 * (15-37 KB, rebuilt from six queries) on every click. The acknowledgement
 * carries exactly what the server stored for that question, so applying it to
 * the question gives the same state the full snapshot would have given; every
 * other question is untouched because only this client changes them.
 */

/** True for a compact acknowledgement ({ id, question: {...} }), false for a full attempt. */
export function isAnswerAck(payload) {
    return Boolean(
        payload
        && typeof payload === 'object'
        && !Array.isArray(payload.questions)
        && payload.question
        && typeof payload.question === 'object'
        && payload.question.id !== undefined,
    );
}

/**
 * Returns the attempt with one acknowledgement applied. A question that has a
 * newer unsaved local change (pending[questionId]) keeps its local state, the
 * same rule the full-snapshot merge follows. An acknowledgement for another
 * attempt is ignored.
 */
export function applyAnswerAck(attempt, ack, pending = {}) {
    if (!attempt || !isAnswerAck(ack)) return attempt;
    if (ack.id !== undefined && attempt.id !== undefined && Number(ack.id) !== Number(attempt.id)) {
        return attempt;
    }

    const saved = ack.question;
    const next = { ...attempt };
    if (ack.expires_at) next.expires_at = ack.expires_at;

    if (!Array.isArray(attempt.questions)) return next;

    const questionId = Number(saved.id);
    if (pending && pending[questionId]) {
        return next;
    }

    next.questions = attempt.questions.map((q) => {
        if (Number(q.id) !== questionId) return q;

        const selectedIds = Array.isArray(saved.selected_option_ids)
            ? saved.selected_option_ids.map(Number)
            : [];
        const selectedSet = new Set(selectedIds);

        return {
            ...q,
            selected_option_ids: selectedIds,
            selected_option_id: saved.selected_option_id ?? null,
            answer_text: saved.answer_text ?? null,
            explanation: saved.explanation ?? null,
            options: Array.isArray(q.options)
                ? q.options.map((opt) => ({ ...opt, selected: selectedSet.has(Number(opt.id)) }))
                : q.options,
        };
    });

    return next;
}
