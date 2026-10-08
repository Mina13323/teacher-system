import { describe, expect, it } from 'vitest';
import { resolveLang } from './index.js';

describe('resolveLang in a non-browser environment', () => {
    it('falls back to "en" when navigator and localStorage are unavailable', () => {
        expect(typeof navigator).toBe('undefined');
        expect(resolveLang()).toBe('en');
    });
});
