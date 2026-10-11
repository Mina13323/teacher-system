import { afterEach, describe, expect, it, vi } from 'vitest';
import i18n from '@/i18n';
import api, { ApiError, setAuthToken, setUnauthorizedHandler } from './client';

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

    it('has a localized message for a temporarily unavailable server', () => {
        i18n.global.locale.value = 'en';
        expect(ApiError.friendly(503)).toBe('The server is busy right now. Your work is safe. Please try again in a moment.');
        i18n.global.locale.value = 'ar';
        expect(ApiError.friendly(503)).toBe('الخادم مشغول الآن. عملك محفوظ. يرجى المحاولة مرة أخرى بعد قليل.');
    });
});

function respondWith(status, data = {}) {
    return (config) => Promise.reject(Object.assign(new Error(`HTTP ${status}`), {
        config,
        response: { status, data, headers: {}, config },
    }));
}

describe('client: revoked sign-in', () => {
    afterEach(() => {
        setUnauthorizedHandler(null);
        setAuthToken(null);
    });

    it('reports a 401 to a request that carried the sign-in token', async () => {
        const handler = vi.fn();
        setUnauthorizedHandler(handler);
        setAuthToken('1|secret');

        await expect(api.get('/student/dashboard', undefined, { adapter: respondWith(401, { code: 'session_transferred' }) }))
            .rejects.toBeInstanceOf(ApiError);

        expect(handler).toHaveBeenCalledTimes(1);
        expect(handler.mock.calls[0][0].data.code).toBe('session_transferred');
    });

    it('leaves sign-in requests, signed-out requests and other errors alone', async () => {
        const handler = vi.fn();
        setUnauthorizedHandler(handler);

        // No token sent (signed out): a 401 is just a failed request.
        await expect(api.post('/auth/login', {}, { adapter: respondWith(401) })).rejects.toBeInstanceOf(ApiError);

        setAuthToken('1|secret');
        await expect(api.get('/auth/me', undefined, { skipAuthHandler: true, adapter: respondWith(401) })).rejects.toBeInstanceOf(ApiError);
        await expect(api.get('/teacher/dashboard', undefined, { adapter: respondWith(403) })).rejects.toBeInstanceOf(ApiError);
        await expect(api.get('/student/exams', undefined, { adapter: respondWith(503) })).rejects.toBeInstanceOf(ApiError);

        expect(handler).not.toHaveBeenCalled();
    });
});
