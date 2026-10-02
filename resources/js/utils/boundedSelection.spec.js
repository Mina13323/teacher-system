import { describe, expect, it } from 'vitest';
import { toggleBoundedSelection } from '@/utils/boundedSelection';

describe('bounded record selection', () => {
    it('allows deselection at the cap', () => {
        const current = Array.from({ length: 100 }, (_, index) => index + 1);
        const result = toggleBoundedSelection(current, 42, 100);

        expect(result.action).toBe('removed');
        expect(result.ids).toHaveLength(99);
        expect(result.ids).not.toContain(42);
    });

    it('rejects additions over the cap without changing the selection', () => {
        const current = Array.from({ length: 100 }, (_, index) => index + 1);
        const result = toggleBoundedSelection(current, 101, 100);

        expect(result.action).toBe('limit');
        expect(result.ids).toBe(current);
    });

    it('lets a stale, newly unavailable selection be removed', () => {
        const result = toggleBoundedSelection([7], 7, 100, true);

        expect(result).toEqual({ ids: [], action: 'removed' });
    });

    it('does not add a newly unavailable record', () => {
        const result = toggleBoundedSelection([], 7, 100, true);

        expect(result).toEqual({ ids: [], action: 'unavailable' });
    });

    it('normalizes ids and does not duplicate an existing selection', () => {
        expect(toggleBoundedSelection([], '7', 100)).toEqual({ ids: [7], action: 'added' });
        expect(toggleBoundedSelection([7], '7', 100)).toEqual({ ids: [], action: 'removed' });
    });

    it('ignores invalid ids', () => {
        const current = [2];
        const result = toggleBoundedSelection(current, 'nope', 100);

        expect(result.action).toBe('invalid');
        expect(result.ids).toBe(current);
    });
});
