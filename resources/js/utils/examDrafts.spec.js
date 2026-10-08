import { describe, expect, it } from 'vitest';
import { collectUnsavedDrafts } from './examDrafts';

const selectionOf = (q) => q.selected_option_ids || [];

const essay = { id: 1, question_type: 'essay', answer_text: 'saved text' };
const mcq = { id: 2, question_type: 'single_choice', explanation_enabled: true, explanation: 'because', selected_option_ids: [20] };
const plainMcq = { id: 3, question_type: 'single_choice', explanation_enabled: false, selected_option_ids: [30] };

describe('collectUnsavedDrafts', () => {
    it('queues essay text typed after the last save', () => {
        const drafts = collectUnsavedDrafts([essay], { essayAnswers: { 1: 'saved text and more' }, selectionOf });
        expect(drafts).toEqual({ 1: { question_id: 1, answer_text: 'saved text and more' } });
    });

    it('sends nothing when the text matches the server copy', () => {
        expect(collectUnsavedDrafts([essay, mcq, plainMcq], {
            essayAnswers: { 1: 'saved text' },
            mcqExplanations: { 2: 'because' },
            selectionOf,
        })).toEqual({});
    });

    it('treats a missing server copy as empty text', () => {
        const blank = { id: 4, question_type: 'essay', answer_text: null };
        expect(collectUnsavedDrafts([blank], { essayAnswers: { 4: '' }, selectionOf })).toEqual({});
        expect(collectUnsavedDrafts([blank], { essayAnswers: { 4: 'x' }, selectionOf })).toEqual({ 4: { question_id: 4, answer_text: 'x' } });
    });

    it('compares against a queued copy before the server copy', () => {
        const pending = { 1: { question_id: 1, answer_text: 'queued' } };
        expect(collectUnsavedDrafts([essay], { essayAnswers: { 1: 'queued' }, pending, selectionOf })).toEqual({});
        expect(collectUnsavedDrafts([essay], { essayAnswers: { 1: 'newer' }, pending, selectionOf }))
            .toEqual({ 1: { question_id: 1, answer_text: 'newer' } });
    });

    it('queues a changed explanation with the current selection', () => {
        expect(collectUnsavedDrafts([mcq], { mcqExplanations: { 2: 'because of X' }, selectionOf }))
            .toEqual({ 2: { question_id: 2, option_ids: [20], explanation: 'because of X' } });
    });

    it('keeps the queued selection when an explanation changes', () => {
        const pending = { 2: { question_id: 2, option_ids: [21], explanation: 'old' } };
        expect(collectUnsavedDrafts([mcq], { mcqExplanations: { 2: 'new' }, pending, selectionOf }))
            .toEqual({ 2: { question_id: 2, option_ids: [21], explanation: 'new' } });
    });

    it('skips explanations without a selected option and questions without explanations', () => {
        const unanswered = { ...mcq, selected_option_ids: [] };
        expect(collectUnsavedDrafts([unanswered, plainMcq], { mcqExplanations: { 2: 'x', 3: 'y' }, selectionOf })).toEqual({});
    });

    it('does not mutate its inputs', () => {
        const pending = {};
        const essayAnswers = { 1: 'changed' };
        collectUnsavedDrafts([essay], { essayAnswers, pending, selectionOf });
        expect(pending).toEqual({});
        expect(essayAnswers).toEqual({ 1: 'changed' });
    });
});
