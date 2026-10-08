import { afterEach, describe, expect, it, vi } from 'vitest';
import { resolveLang } from './index.js';

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('resolveLang in a non-browser environment', () => {
    it('falls back to "en" when navigator and localStorage are unavailable', () => {
        // Node 21+ defines a global navigator, so remove it explicitly.
        vi.stubGlobal('navigator', undefined);
        vi.stubGlobal('localStorage', undefined);

        expect(typeof navigator).toBe('undefined');
        expect(resolveLang()).toBe('en');
    });
});
