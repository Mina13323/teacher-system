import { describe, expect, it } from 'vitest';
import { groupAttemptsByExam, scoreText } from './studentAttempts.js';

describe('student attempt history (P1-E)', () => {
    it('groups consecutive attempts of one exam and keeps their order', () => {
        const rows = [
            { attempt_id: 9, exam_id: 2, exam_title: 'Unit 2', attempt_number: 1 },
            { attempt_id: 3, exam_id: 1, exam_title: 'Unit 1', attempt_number: 1 },
            { attempt_id: 5, exam_id: 1, exam_title: 'Unit 1', attempt_number: 2 },
        ];
        const groups = groupAttemptsByExam(rows);
        expect(groups.map((g) => g.exam_title)).toEqual(['Unit 2', 'Unit 1']);
        expect(groups[1].attempts.map((a) => a.attempt_number)).toEqual([1, 2]);
        expect(groupAttemptsByExam(null)).toEqual([]);
    });

    it('shows a real zero as 0 and a missing score as a dash', () => {
        expect(scoreText(0, 0)).toBe('0 (0%)');
        expect(scoreText(7, 70)).toBe('7 (70%)');
        expect(scoreText(null, null)).toBe('—');
        expect(scoreText(undefined, 50)).toBe('—');
        expect(scoreText(4, null)).toBe('4');
    });
});
