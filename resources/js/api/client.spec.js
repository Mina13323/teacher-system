import { afterEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { ApiError } from './client';

describe('ApiError.friendly', () => {
    afterEach(() => {
        i18n.global.locale.value = 'en';
    });

    it('tells a timeout apart from an unreachable server', () => {
        i18n.global.locale.value = 'en';
        expect(ApiError.friendly(0, { timedOut: true })).toBe('The server took too long to respond. Please try again.');
        expect(ApiError.friendly(0)).toBe('Could not reach the server. Check your connection and try again.');
    });

    it('follows the active locale', () => {
        i18n.global.locale.value = 'ar';
        expect(ApiError.friendly(0, { timedOut: true })).toBe('استغرق الخادم وقتًا طويلًا للرد. يرجى المحاولة مرة أخرى.');
        expect(ApiError.friendly(403)).toBe('ليس لديك صلاحية لتنفيذ هذا الإجراء.');
    });

    it('falls back to the generic message for unmapped statuses', () => {
        i18n.global.locale.value = 'en';
        expect(ApiError.friendly(502)).toBe('Something went wrong. Please try again.');
    });
});
