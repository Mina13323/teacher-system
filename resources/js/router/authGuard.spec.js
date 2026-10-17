import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

const me = vi.fn();
vi.mock('@/api', () => ({ auth: { me: (...args) => me(...args) } }));

const { ApiError } = await import('@/api/client');
const { useAuthStore, ME_RETRY_DELAYS_MS } = await import('@/stores/auth');
const { authGuard } = await import('./authGuard');

function storage() {
    const data = new Map();
    return {
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, String(v)),
        removeItem: (k) => data.delete(k),
    };
}

const ATTEMPTS_PER_CHECK = ME_RETRY_DELAYS_MS.length + 1;

const studentPage = { name: 'student.exams', path: '/student/exams', fullPath: '/student/exams', meta: { roles: ['student'] } };
const reconnectPage = { name: 'reconnect', path: '/reconnect', fullPath: '/reconnect?redirect=%2Fstudent%2Fexams', meta: { public: true } };

/** Runs the guard while letting the store's retry waits elapse. */
async function navigate(to, auth) {
    const result = authGuard(to, auth);
    await vi.runAllTimersAsync();
    return result;
}

/** Follows guard redirects the way the router does, counting each hop. */
async function navigateFollowingRedirects(to, auth) {
    let target = to;
    for (let hop = 0; hop < 5; hop++) {
        const result = await navigate(target, auth);
        if (result === true) return target;
        if (result?.name === 'reconnect') {
            target = reconnectPage;
            continue;
        }
        return result;
    }
    throw new Error('redirect loop');
}

let auth;

beforeEach(() => {
    vi.useFakeTimers();
    globalThis.localStorage = storage();
    localStorage.setItem('atlas.auth.token', '1|secret');
    setActivePinia(createPinia());
    me.mockReset();
    auth = useAuthStore();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('router auth guard and the reconnect screen', () => {
    it('runs one check sequence on a transient boot failure, not a second one for the reconnect redirect', async () => {
        me.mockRejectedValue(new ApiError('busy', { status: 503 }));

        const landed = await navigateFollowingRedirects(studentPage, auth);

        expect(landed).toBe(reconnectPage);
        expect(me).toHaveBeenCalledTimes(ATTEMPTS_PER_CHECK);
        expect(auth.token).toBe('1|secret');
    });

    it('lets the Retry button check again explicitly, and continues once it succeeds', async () => {
        me.mockRejectedValue(new ApiError('busy', { status: 503 }));
        await navigateFollowingRedirects(studentPage, auth);
        expect(me).toHaveBeenCalledTimes(ATTEMPTS_PER_CHECK);

        // Reconnect.vue's Retry calls fetchMe directly.
        me.mockReset();
        me.mockResolvedValue({ id: 3, roles: ['student'] });
        const retry = auth.fetchMe();
        await vi.runAllTimersAsync();
        await retry;

        expect(me).toHaveBeenCalledTimes(1);
        expect(auth.booted).toBe(true);
        // The redirect after a successful retry passes without another check.
        expect(await navigate(studentPage, auth)).toBe(true);
        expect(me).toHaveBeenCalledTimes(1);
    });

    it('still signs out and sends the user to login on a genuine 401', async () => {
        me.mockRejectedValue(new ApiError('Unauthenticated.', { status: 401 }));

        const result = await navigate(studentPage, auth);

        expect(result).toEqual({ name: 'login', query: { redirect: '/student/exams' } });
        expect(me).toHaveBeenCalledTimes(1);
        expect(auth.token).toBeNull();
    });

    it('checks again on a later normal navigation', async () => {
        me.mockRejectedValue(new ApiError('busy', { status: 503 }));
        await navigateFollowingRedirects(studentPage, auth);
        expect(me).toHaveBeenCalledTimes(ATTEMPTS_PER_CHECK);

        me.mockReset();
        me.mockResolvedValue({ id: 3, roles: ['student'] });
        const result = await navigate(studentPage, auth);

        expect(me).toHaveBeenCalledTimes(1);
        expect(result).toBe(true);
    });

    it('still checks when the reconnect page is opened directly on a fresh load', async () => {
        me.mockResolvedValue({ id: 3, roles: ['student'] });

        expect(await navigate(reconnectPage, auth)).toBe(true);
        expect(me).toHaveBeenCalledTimes(1);
        expect(auth.booted).toBe(true);
    });

    it('boots normally on success', async () => {
        me.mockResolvedValue({ id: 3, roles: ['student'] });

        expect(await navigate(studentPage, auth)).toBe(true);
        expect(me).toHaveBeenCalledTimes(1);

        // Already booted: no further checks on navigation.
        expect(await navigate(studentPage, auth)).toBe(true);
        expect(me).toHaveBeenCalledTimes(1);
    });
});
