import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

const me = vi.fn();
const transferSession = vi.fn();
vi.mock('@/api', () => ({
    auth: { me: (...args) => me(...args), transferSession: (...args) => transferSession(...args) },
}));

const { ApiError } = await import('@/api/client');
const { useAuthStore, ME_RETRY_DELAYS_MS } = await import('./auth');

function storage() {
    const data = new Map();
    return {
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, String(v)),
        removeItem: (k) => data.delete(k),
    };
}

function failure(status, extra = {}) {
    return new ApiError('failed', { status, ...extra });
}

const noWait = () => Promise.resolve();

describe('auth store fetchMe', () => {
    beforeEach(() => {
        globalThis.localStorage = storage();
        localStorage.setItem('atlas.auth.token', '1|secret');
        setActivePinia(createPinia());
        me.mockReset();
    });

    it('signs out on a genuine 401', async () => {
        me.mockRejectedValue(failure(401));
        const auth = useAuthStore();

        await expect(auth.fetchMe({ wait: noWait })).rejects.toBeInstanceOf(ApiError);

        expect(me).toHaveBeenCalledTimes(1);
        expect(auth.token).toBeNull();
        expect(localStorage.getItem('atlas.auth.token')).toBeNull();
        expect(auth.booted).toBe(true);
    });

    it.each([
        ['500', failure(500)],
        ['502', failure(502)],
        ['503', failure(503)],
        ['504', failure(504)],
        ['429', failure(429)],
        ['a network error', failure(0)],
        ['a timeout', failure(0, { code: 'ECONNABORTED' })],
    ])('keeps the token after %s', async (_label, error) => {
        me.mockRejectedValue(error);
        const auth = useAuthStore();

        await expect(auth.fetchMe({ wait: noWait })).rejects.toBe(error);

        expect(auth.token).toBe('1|secret');
        expect(localStorage.getItem('atlas.auth.token')).toBe('1|secret');
        expect(auth.bootError).toBe(error);
        // Not booted, so the next navigation asks /auth/me again.
        expect(auth.booted).toBe(false);
    });

    it('retries a transient failure a bounded number of times', async () => {
        me.mockRejectedValue(failure(503));
        const waits = [];
        const auth = useAuthStore();

        await expect(auth.fetchMe({ wait: (ms) => { waits.push(ms); return Promise.resolve(); }, random: () => 0.5 })).rejects.toBeTruthy();

        expect(me).toHaveBeenCalledTimes(ME_RETRY_DELAYS_MS.length + 1);
        expect(waits).toEqual(ME_RETRY_DELAYS_MS);
    });

    it('keeps retry waits within ±30% jitter', async () => {
        for (const random of [0, 0.999]) {
            me.mockReset();
            me.mockRejectedValue(failure(500));
            const waits = [];
            const auth = useAuthStore();
            await expect(auth.fetchMe({ wait: (ms) => { waits.push(ms); return Promise.resolve(); }, random: () => random })).rejects.toBeTruthy();
            waits.forEach((ms, i) => {
                expect(ms).toBeGreaterThanOrEqual(ME_RETRY_DELAYS_MS[i] * 0.7 - 1);
                expect(ms).toBeLessThanOrEqual(ME_RETRY_DELAYS_MS[i] * 1.3 + 1);
            });
        }
    });

    it('recovers when a retry succeeds', async () => {
        const user = { id: 7, roles: ['student'] };
        me.mockRejectedValueOnce(failure(503)).mockResolvedValueOnce(user);
        const auth = useAuthStore();

        await expect(auth.fetchMe({ wait: noWait })).resolves.toEqual(user);

        expect(auth.user).toEqual(user);
        expect(auth.bootError).toBeNull();
        expect(auth.booted).toBe(true);
    });

    it('does not retry a 401 after a transient failure', async () => {
        me.mockRejectedValueOnce(failure(500)).mockRejectedValueOnce(failure(401));
        const auth = useAuthStore();

        await expect(auth.fetchMe({ wait: noWait })).rejects.toBeTruthy();

        expect(me).toHaveBeenCalledTimes(2);
        expect(auth.token).toBeNull();
    });
});

describe('auth store: persistent sign-in', () => {
    beforeEach(() => {
        globalThis.localStorage = storage();
        localStorage.setItem('atlas.auth.token', '1|secret');
        setActivePinia(createPinia());
        me.mockReset();
        transferSession.mockReset();
    });

    it('restores the user from the stored token after a reload or restart, with one /auth/me', async () => {
        me.mockResolvedValue({ id: 7, roles: ['student'], unread_count: 2 });
        // A new page load (or browser restart) builds a new store from storage.
        const auth = useAuthStore();
        expect(auth.isAuthenticated).toBe(true);

        await auth.fetchMe({ wait: noWait });

        expect(me).toHaveBeenCalledTimes(1);
        expect(auth.user.id).toBe(7);
        expect(auth.booted).toBe(true);
    });

    it.each([403, 404, 422])('keeps the sign-in when /auth/me answers %s (not a verdict on the token)', async (status) => {
        me.mockRejectedValue(failure(status));
        const auth = useAuthStore();

        await expect(auth.fetchMe({ wait: noWait })).rejects.toBeInstanceOf(ApiError);

        expect(auth.token).toBe('1|secret');
        expect(localStorage.getItem('atlas.auth.token')).toBe('1|secret');
        expect(auth.bootError).not.toBeNull();
    });

    it('keeps the sign-in when a proxy answers /auth/me with a page instead of the user', async () => {
        me.mockResolvedValue('<!doctype html><html></html>');
        const auth = useAuthStore();

        await expect(auth.fetchMe({ wait: noWait })).rejects.toBeInstanceOf(ApiError);

        expect(auth.token).toBe('1|secret');
        expect(auth.user).toBeNull();
        expect(auth.bootError).not.toBeNull();
    });

    it('remembers that the account moved to another device', async () => {
        me.mockRejectedValue(failure(401, { data: { code: 'session_transferred' } }));
        const auth = useAuthStore();

        await expect(auth.fetchMe({ wait: noWait })).rejects.toBeInstanceOf(ApiError);

        expect(auth.token).toBeNull();
        expect(auth.endedReason).toBe('session_transferred');
    });

    it('ends a sign-in revoked mid-session and says why', () => {
        const auth = useAuthStore();

        expect(auth.handleUnauthorized(failure(401))).toBe(true);
        expect(auth.token).toBeNull();
        expect(localStorage.getItem('atlas.auth.token')).toBeNull();
        expect(auth.endedReason).toBe('session_ended');

        // Already signed out: nothing more to do.
        expect(auth.handleUnauthorized(failure(401))).toBe(false);
    });

    it('keeps an exam recovery copy when a sign-in ends', () => {
        localStorage.setItem('exam.recovery.42', '{"answers":{}}');
        const auth = useAuthStore();

        auth.handleUnauthorized(failure(401, { data: { code: 'session_transferred' } }));

        expect(auth.endedReason).toBe('session_transferred');
        expect(localStorage.getItem('exam.recovery.42')).toBe('{"answers":{}}');
    });

    it('signs in on this device after a confirmed transfer', async () => {
        localStorage.removeItem('atlas.auth.token');
        transferSession.mockResolvedValue({ user: { id: 7, roles: ['student'] }, token: '2|new', unread_count: 0 });
        const auth = useAuthStore();
        auth.endedReason = 'session_transferred';

        await auth.transferSession('ticket');

        expect(transferSession).toHaveBeenCalledWith({ transfer_token: 'ticket' });
        expect(auth.token).toBe('2|new');
        expect(localStorage.getItem('atlas.auth.token')).toBe('2|new');
        expect(auth.endedReason).toBeNull();
    });
});
