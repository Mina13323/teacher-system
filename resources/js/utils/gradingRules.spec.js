import { describe, expect, it } from 'vitest';
import { canOpenGrading, isGradable } from './gradingRules.js';

describe('grading actions offered on the teacher attempt screen (P0-1)', () => {
    it('never offers grading for a running attempt', () => {
        expect(canOpenGrading({ status: 'in_progress' })).toBe(false);
        expect(canOpenGrading('in_progress')).toBe(false);
        expect(canOpenGrading(null)).toBe(false);
    });

    it('opens handed-in and expired attempts', () => {
        for (const status of ['submitted', 'grading', 'published', 'expired']) {
            expect(canOpenGrading({ status })).toBe(true);
        }
    });

    it('offers grade entry and publication only for handed-in attempts', () => {
        expect(isGradable({ status: 'submitted' })).toBe(true);
        expect(isGradable({ status: 'grading' })).toBe(true);
        expect(isGradable({ status: 'published' })).toBe(true);
        expect(isGradable({ status: 'expired' })).toBe(false);
        expect(isGradable({ status: 'in_progress' })).toBe(false);
        expect(isGradable(undefined)).toBe(false);
    });
});
