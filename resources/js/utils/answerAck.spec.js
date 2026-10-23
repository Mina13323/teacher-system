import { describe, expect, it } from 'vitest';
import { applyAnswerAck, isAnswerAck } from './answerAck.js';

const attempt = () => ({
    id: 7,
    status: 'in_progress',
    expires_at: '2026-10-08T10:30:00.000000Z',
    questions: [
        {
            id: 1,
            question_type: 'single_choice',
            selected_option_ids: [],
            selected_option_id: null,
            explanation: null,
            answer_text: null,
            options: [{ id: 11, selected: false }, { id: 12, selected: false }],
        },
        {
            id: 2,
            question_type: 'multiple_choice',
            selected_option_ids: [21],
            selected_option_id: 21,
            explanation: 'old',
            answer_text: null,
            options: [{ id: 21, selected: true }, { id: 22, selected: false }, { id: 23, selected: false }],
        },
        { id: 3, question_type: 'essay', answer_text: 'draft', selected_option_ids: [], options: [] },
    ],
});

const ack = (question, extra = {}) => ({ id: 7, status: 'in_progress', expires_at: '2026-10-08T10:30:00.000000Z', question, ...extra });

describe('isAnswerAck', () => {
    it('recognises a compact acknowledgement', () => {
        expect(isAnswerAck(ack({ id: 1, selected_option_ids: [11] }))).toBe(true);
    });

    it('does not treat a full attempt or junk as an acknowledgement', () => {
        expect(isAnswerAck(attempt())).toBe(false);
        expect(isAnswerAck(null)).toBe(false);
        expect(isAnswerAck({ id: 7 })).toBe(false);
        expect(isAnswerAck({ id: 7, question: {} })).toBe(false);
    });
});

describe('applyAnswerAck', () => {
    it('applies a single-choice selection to that question only', () => {
        const before = attempt();
        const after = applyAnswerAck(before, ack({ id: 1, selected_option_id: 12, selected_option_ids: [12], explanation: null, answer_text: null }));

        expect(after.questions[0].selected_option_ids).toEqual([12]);
        expect(after.questions[0].selected_option_id).toBe(12);
        expect(after.questions[0].options.map((o) => o.selected)).toEqual([false, true]);
        expect(after.questions[1]).toBe(before.questions[1]);
        expect(after.questions[2]).toBe(before.questions[2]);
        expect(before.questions[0].selected_option_ids).toEqual([]);
    });

    it('applies a multi-select set and the stored explanation', () => {
        const after = applyAnswerAck(attempt(), ack({ id: 2, selected_option_id: null, selected_option_ids: [22, 23], explanation: 'new', answer_text: null }));

        expect(after.questions[1].selected_option_ids).toEqual([22, 23]);
        expect(after.questions[1].selected_option_id).toBeNull();
        expect(after.questions[1].explanation).toBe('new');
        expect(after.questions[1].options.map((o) => o.selected)).toEqual([false, true, true]);
    });

    it('applies a cleared selection', () => {
        const after = applyAnswerAck(attempt(), ack({ id: 2, selected_option_id: null, selected_option_ids: [], explanation: null, answer_text: null }));

        expect(after.questions[1].selected_option_ids).toEqual([]);
        expect(after.questions[1].options.every((o) => !o.selected)).toBe(true);
    });

    it('applies essay text', () => {
        const after = applyAnswerAck(attempt(), ack({ id: 3, selected_option_id: null, selected_option_ids: [], explanation: null, answer_text: 'final' }));

        expect(after.questions[2].answer_text).toBe('final');
    });

    it('keeps a newer local change for the same question', () => {
        const before = attempt();
        const after = applyAnswerAck(before, ack({ id: 1, selected_option_id: 11, selected_option_ids: [11] }), { 1: { question_id: 1, option_ids: [12] } });

        expect(after.questions[0]).toBe(before.questions[0]);
    });

    it('takes a deadline the server changed', () => {
        const after = applyAnswerAck(attempt(), ack({ id: 1, selected_option_ids: [11] }, { expires_at: '2026-10-08T10:40:00.000000Z' }));

        expect(after.expires_at).toBe('2026-10-08T10:40:00.000000Z');
    });

    it('ignores an acknowledgement for another attempt', () => {
        const before = attempt();

        expect(applyAnswerAck(before, { ...ack({ id: 1, selected_option_ids: [11] }), id: 8 })).toBe(before);
    });

    it('accepts string ids', () => {
        const after = applyAnswerAck(attempt(), { ...ack({ id: '1', selected_option_ids: ['12'] }), id: '7' });

        expect(after.questions[0].selected_option_ids).toEqual([12]);
        expect(after.questions[0].options[1].selected).toBe(true);
    });
});
