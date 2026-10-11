import { describe, expect, it } from 'vitest';
import { canOpenGrading, gradeFieldValue, isGradable, parseAwardedPoints, ungradedEssayCount, ungradedEssaysFromError } from './gradingRules.js';

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

describe('essay points entry (P0-3)', () => {
    it('an empty field is not graded and is never sent as 0', () => {
        for (const raw of ['', '   ', null, undefined]) {
            expect(parseAwardedPoints(raw, 10)).toEqual({ ok: false, reason: 'empty' });
        }
    });

    it('an explicit zero is a grade', () => {
        expect(parseAwardedPoints('0', 10)).toEqual({ ok: true, value: 0 });
        expect(parseAwardedPoints(0, 10)).toEqual({ ok: true, value: 0 });
    });

    it('accepts whole points up to the question maximum', () => {
        expect(parseAwardedPoints('7', 10)).toEqual({ ok: true, value: 7 });
        expect(parseAwardedPoints('10', 10)).toEqual({ ok: true, value: 10 });
        expect(parseAwardedPoints(' 4 ', 10)).toEqual({ ok: true, value: 4 });
    });

    it('refuses negative, too large, fractional and non-numeric values', () => {
        expect(parseAwardedPoints('-1', 10)).toEqual({ ok: false, reason: 'range' });
        expect(parseAwardedPoints('11', 10)).toEqual({ ok: false, reason: 'range' });
        expect(parseAwardedPoints('2.5', 10)).toEqual({ ok: false, reason: 'invalid' });
        expect(parseAwardedPoints('abc', 10)).toEqual({ ok: false, reason: 'invalid' });
        expect(parseAwardedPoints('1e1', 10)).toEqual({ ok: false, reason: 'invalid' });
    });

    it('does not invent a zero maximum when none is known', () => {
        expect(parseAwardedPoints('3', null)).toEqual({ ok: true, value: 3 });
        expect(parseAwardedPoints('3', undefined)).toEqual({ ok: true, value: 3 });
    });

    it('shows an ungraded essay as an empty field and a graded zero as 0', () => {
        expect(gradeFieldValue(null)).toBe('');
        expect(gradeFieldValue(undefined)).toBe('');
        expect(gradeFieldValue(0)).toBe('0');
        expect(gradeFieldValue(6)).toBe('6');
    });
});

describe('publication waits for every essay grade (P0-4)', () => {
    const questions = [
        { id: 1, question_type: 'single_choice', points_earned: 0 },
        { id: 2, question_type: 'essay', points_earned: null },
        { id: 3, question_type: 'essay', points_earned: 0 },
        { id: 4, question_type: 'essay' },
        { id: 5, type: 'essay', points_earned: 5 },
    ];

    it('counts ungraded and blank essays, not graded zeros or choice questions', () => {
        expect(ungradedEssayCount(questions)).toBe(2);
        expect(ungradedEssayCount([])).toBe(0);
        expect(ungradedEssayCount(undefined)).toBe(0);
    });

    it('reads the outstanding count from a refused publication only', () => {
        expect(ungradedEssaysFromError({ status: 422, data: { code: 'essays_ungraded', data: { ungraded_essays: 3 } } })).toBe(3);
        expect(ungradedEssaysFromError({ status: 422, data: { message: 'still in progress' } })).toBeNull();
        expect(ungradedEssaysFromError({ status: 500, data: { code: 'essays_ungraded' } })).toBeNull();
        expect(ungradedEssaysFromError(null)).toBeNull();
    });
});
