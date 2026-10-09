/**
 * Tracks which MCQ explanations the server has acknowledged.
 *
 * Manual submit used to re-send every explanation-enabled answer, even when
 * the server already had exactly that text. This keeps, per question, the
 * last explanation text the server confirmed saving, plus a dirty flag set on
 * every edit. Only a successful save response clears the flag, and only when
 * the text that was sent is still the current text; nothing local is ever
 * assumed to be on the server.
 */
export function createExplanationAcks() {
    const acknowledged = new Map();
    const dirty = new Set();

    const key = (questionId) => Number(questionId);

    return {
        /** The server's copy, from a freshly read attempt snapshot. */
        setServerValue(questionId, text) {
            acknowledged.set(key(questionId), text ?? '');
        },

        /** The student edited the explanation. */
        markDirty(questionId) {
            dirty.add(key(questionId));
        },

        /**
         * The server confirmed saving `sentText`. The flag clears only when the
         * student has not typed something newer since that save was sent.
         */
        acknowledge(questionId, sentText, currentText) {
            const id = key(questionId);
            acknowledged.set(id, sentText ?? '');
            if ((currentText ?? '') === (sentText ?? '')) {
                dirty.delete(id);
            }
        },

        /** True when `currentText` may not be on the server yet. */
        needsSave(questionId, currentText) {
            const id = key(questionId);
            if (dirty.has(id) || !acknowledged.has(id)) return true;
            return acknowledged.get(id) !== (currentText ?? '');
        },
    };
}

/**
 * The explanation-enabled MCQ questions whose explanation must be sent before
 * a manual submit: answered (a selection exists) and not yet acknowledged by
 * the server in its current form.
 */
export function explanationsNeedingSave(questions, { texts = {}, acks, selectionOf }) {
    return (questions || []).filter((q) => (
        q.explanation_enabled
        && q.question_type !== 'essay'
        && selectionOf(q).length > 0
        && acks.needsSave(q.id, texts[q.id] ?? '')
    ));
}
