import { describe, expect, it, vi } from 'vitest';
import { createExplanationAcks, explanationsNeedingSave } from './explanationAcks';

const selectionOf = (q) => q.selected ?? [];

function question(id, overrides = {}) {
    return { id, question_type: 'single_choice', explanation_enabled: true, selected: [10 + id], ...overrides };
}

/**
 * Mirrors the manual-submit loop in ExamTake: send what needs saving, and
 * acknowledge only on a successful response.
 */
async function submitExplanations(questions, texts, acks, send) {
    for (const q of explanationsNeedingSave(questions, { texts, acks, selectionOf })) {
        const sent = texts[q.id] ?? '';
        try {
            await send(q.id, sent);
            acks.acknowledge(q.id, sent, texts[q.id]);
        } catch {
            /* stays dirty */
        }
    }
}

describe('explanation acknowledgements', () => {
    it('sends nothing for an explanation the server already has', async () => {
        const acks = createExplanationAcks();
        const qs = [question(1), question(2)];
        acks.setServerValue(1, 'because A');
        acks.setServerValue(2, 'because B');
        const send = vi.fn().mockResolvedValue({});

        await submitExplanations(qs, { 1: 'because A', 2: 'because B' }, acks, send);

        expect(send).not.toHaveBeenCalled();
    });

    it('sends a changed explanation', async () => {
        const acks = createExplanationAcks();
        const qs = [question(1), question(2)];
        acks.setServerValue(1, 'because A');
        acks.setServerValue(2, 'because B');
        const texts = { 1: 'because A', 2: 'because B, really' };
        acks.markDirty(2);
        const send = vi.fn().mockResolvedValue({});

        await submitExplanations(qs, texts, acks, send);

        expect(send).toHaveBeenCalledTimes(1);
        expect(send).toHaveBeenCalledWith(2, 'because B, really');
    });

    it('keeps a failed save dirty so the next submit sends it again', async () => {
        const acks = createExplanationAcks();
        const qs = [question(1)];
        acks.setServerValue(1, '');
        const texts = { 1: 'new text' };
        acks.markDirty(1);
        const send = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue({});

        await submitExplanations(qs, texts, acks, send);
        expect(acks.needsSave(1, 'new text')).toBe(true);

        await submitExplanations(qs, texts, acks, send);
        expect(send).toHaveBeenCalledTimes(2);
        expect(acks.needsSave(1, 'new text')).toBe(false);
    });

    it('clears the dirty state after a successful save of the current text', async () => {
        const acks = createExplanationAcks();
        acks.setServerValue(1, '');
        acks.markDirty(1);

        acks.acknowledge(1, 'saved', 'saved');

        expect(acks.needsSave(1, 'saved')).toBe(false);
    });

    it('sends again after an acknowledged explanation is changed', async () => {
        const acks = createExplanationAcks();
        const qs = [question(1)];
        acks.setServerValue(1, 'first');
        const texts = { 1: 'first' };
        const send = vi.fn().mockResolvedValue({});

        await submitExplanations(qs, texts, acks, send);
        expect(send).not.toHaveBeenCalled();

        texts[1] = 'second';
        acks.markDirty(1);
        await submitExplanations(qs, texts, acks, send);
        expect(send).toHaveBeenCalledWith(1, 'second');

        await submitExplanations(qs, texts, acks, send);
        expect(send).toHaveBeenCalledTimes(1);
    });

    it('stays dirty when the student typed more while the save was in flight', () => {
        const acks = createExplanationAcks();
        acks.setServerValue(1, '');
        acks.markDirty(1);

        // "abc" was sent; the student has since typed "abcd".
        acks.acknowledge(1, 'abc', 'abcd');

        expect(acks.needsSave(1, 'abcd')).toBe(true);
    });

    it('treats an explanation the server never reported as unsaved', () => {
        const acks = createExplanationAcks();
        expect(acks.needsSave(5, '')).toBe(true);
    });

    it('treats recovered local text that differs from the server copy as unsaved', () => {
        const acks = createExplanationAcks();
        acks.setServerValue(1, 'server copy');
        expect(acks.needsSave(1, 'typed before reload')).toBe(true);
    });

    it('skips unanswered, essay and explanation-disabled questions', () => {
        const acks = createExplanationAcks();
        const qs = [
            question(1, { selected: [] }),
            question(2, { question_type: 'essay' }),
            question(3, { explanation_enabled: false }),
            question(4),
        ];

        const due = explanationsNeedingSave(qs, { texts: {}, acks, selectionOf });

        expect(due.map((q) => q.id)).toEqual([4]);
    });
});
