import { describe, expect, it } from 'vitest';
import { toIso, toLocalInput } from './datetimeLocal';

describe('datetimeLocal', () => {
    it('round-trips a local input value through ISO', () => {
        const local = '2026-11-02T18:30';
        expect(toLocalInput(toIso(local))).toBe(local);
    });

    it('treats empty or invalid values as empty', () => {
        expect(toIso('')).toBeNull();
        expect(toIso('not a date')).toBeNull();
        expect(toLocalInput(null)).toBe('');
        expect(toLocalInput('nonsense')).toBe('');
    });
});
