import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

const me = vi.fn();
vi.mock('@/api', () => ({ auth: { me: (...args) => me(...args) } }));

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
