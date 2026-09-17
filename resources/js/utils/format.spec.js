import { afterEach, describe, expect, it } from 'vitest';

import { currentLocale, formatDate, formatDateTime, formatTime } from '@/utils/format';

const ISO = '2026-09-17T14:35:00Z';
const EM_DASH = '\u2014';

/** The formatter reads the active language from <html lang>. */
function setLocale(lang) {
    globalThis.document = { documentElement: { lang } };
}

afterEach(() => {
    delete globalThis.document;
});

describe('currentLocale', () => {
    it('falls back to English when there is no document', () => {
        expect(currentLocale()).toBe('en');
    });

    it('reads the active language from the document element', () => {
        setLocale('ar');
        expect(currentLocale()).toBe('ar');
    });
});

describe('formatDate', () => {
    it('renders the date without a time', () => {
        setLocale('en');
        const out = formatDate(ISO);

        expect(out).toMatch(/2026/);
        expect(out).not.toMatch(/AM|PM/i);
    });

    it.each([null, undefined, ''])('renders an em dash for empty input (%s)', (value) => {
        expect(formatDate(value)).toBe(EM_DASH);
    });

    it('renders an em dash for an unparseable date instead of throwing', () => {
        expect(() => formatDate('not-a-date')).not.toThrow();
        expect(formatDate('not-a-date')).toBe(EM_DASH);
    });

    it('accepts a Date instance as well as a string', () => {
        setLocale('en');
        expect(formatDate(new Date(ISO))).toBe(formatDate(ISO));
    });
});

describe('formatDateTime', () => {
    it('includes the time alongside the date', () => {
        setLocale('en');
        const out = formatDateTime(ISO);

        expect(out).toMatch(/2026/);
        expect(out).toMatch(/AM|PM/i);
    });

    it('formats for the active locale rather than a hardcoded one', () => {
        setLocale('en');
        const english = formatDateTime(ISO);

        setLocale('ar');
        const arabic = formatDateTime(ISO);

        expect(arabic).toMatch(/2026/);
        expect(arabic).not.toBe(english);
    });

    it('never returns an empty string, so no UI renders a blank timestamp', () => {
        expect(formatDateTime(null)).toBe(EM_DASH);
        expect(formatDateTime(null)).not.toBe('');
    });
});

describe('formatTime', () => {
    it('renders only the time component', () => {
        setLocale('en');
        const out = formatTime(ISO);

        expect(out).toMatch(/AM|PM/i);
        expect(out).not.toMatch(/2026/);
    });

    it('renders an em dash for empty input', () => {
        expect(formatTime(null)).toBe(EM_DASH);
    });
});
