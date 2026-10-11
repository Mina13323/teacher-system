import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

const api = {
    all: vi.fn(),
    unreadCount: vi.fn(),
    markRead: vi.fn(async () => null),
    markAllRead: vi.fn(async () => null),
};
const me = vi.fn();
const login = vi.fn();
const logout = vi.fn(async () => null);
vi.mock('@/api', () => ({
    notifications: api,
    toList: (res) => ({ items: res?.data || [], meta: res?.meta || null }),
    auth: { me: (...a) => me(...a), login: (...a) => login(...a), logout: (...a) => logout(...a) },
}));

const { useNotificationsStore, examResultNotifies } = await import('./notifications');
const { useAuthStore } = await import('./auth');

function storage() {
    const data = new Map();
    return {
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, String(v)),
        removeItem: (k) => data.delete(k),
    };
}

function deferred() {
    let resolve;
    const promise = new Promise((r) => { resolve = r; });
    return { promise, resolve };
}

beforeEach(() => {
    globalThis.localStorage = storage();
    setActivePinia(createPinia());
    Object.values(api).forEach((f) => f.mockClear());
    api.unreadCount.mockReset();
    me.mockReset();
    login.mockReset();
});

describe('notifications store (B2)', () => {
    it('adopts a count sent with the user and ignores a missing one', () => {
        const store = useNotificationsStore();
        store.setUnread(4);
        expect(store.unread).toBe(4);
        store.setUnread(undefined);
        store.setUnread(null);
        store.setUnread('abc');
        expect(store.unread).toBe(4);
        store.setUnread(-3);
        expect(store.unread).toBe(0);
    });

    it('reading one notification lowers the badge by one; reading all clears it', async () => {
        const store = useNotificationsStore();
        store.setUnread(3);
        store.items = [{ id: 'a', read_at: null }, { id: 'b', read_at: null }, { id: 'c', read_at: '2026-01-01' }];
        await store.markRead('a');
        expect(store.unread).toBe(2);
        await store.markRead('a'); // already read: no double decrement
        await store.markRead('c');
        expect(store.unread).toBe(2);
        await store.markAllRead();
        expect(store.unread).toBe(0);
        expect(store.items.every((n) => n.read_at)).toBe(true);
    });

    it('a count requested before a sign-out is dropped when it arrives after', async () => {
        const store = useNotificationsStore();
        const pending = deferred();
        api.unreadCount.mockReturnValue(pending.promise);
        const refreshing = store.refreshUnread();
        store.reset();
        pending.resolve({ unread_count: 9 });
        await refreshing;
        expect(store.unread).toBe(0);
    });

    it('only a published result means finishing an exam added a notification', () => {
        expect(examResultNotifies({ grades_published: true })).toBe(true);
        expect(examResultNotifies({ grades_published: false })).toBe(false);
        expect(examResultNotifies({ status: 'grading' })).toBe(false);
        expect(examResultNotifies(null)).toBe(false);
    });
});

describe('badge count from the auth responses (B2)', () => {
    it('/auth/me sets the badge with no separate request', async () => {
        localStorage.setItem('atlas.auth.token', '1|a');
        me.mockResolvedValue({ id: 1, roles: ['student'], unread_count: 5 });
        const auth = useAuthStore();
        await auth.fetchMe();
        expect(useNotificationsStore().unread).toBe(5);
        expect(api.unreadCount).not.toHaveBeenCalled();
    });

    it('signing in starts from the new user\'s count, never the previous one', async () => {
        const store = useNotificationsStore();
        store.setUnread(7);
        store.items = [{ id: 'old', read_at: null }];
        login.mockResolvedValue({ user: { id: 2, roles: ['student'] }, token: '2|b', unread_count: 1 });
        await useAuthStore().login({ email: 'x', password: 'y' });
        expect(store.unread).toBe(1);
        expect(store.items).toEqual([]);
    });

    it('a login response without a count leaves the badge at zero, not the previous user\'s', async () => {
        const store = useNotificationsStore();
        store.setUnread(7);
        login.mockResolvedValue({ user: { id: 2, roles: ['student'] }, token: '2|b' });
        await useAuthStore().login({ email: 'x', password: 'y' });
        expect(store.unread).toBe(0);
    });

    it('signing out clears the badge and inbox', async () => {
        localStorage.setItem('atlas.auth.token', '1|a');
        me.mockResolvedValue({ id: 1, roles: ['student'], unread_count: 3 });
        const auth = useAuthStore();
        await auth.fetchMe();
        useNotificationsStore().items = [{ id: 'x', read_at: null }];
        await auth.logout();
        expect(useNotificationsStore().unread).toBe(0);
        expect(useNotificationsStore().items).toEqual([]);
    });

    it('a 401 on /auth/me clears the badge too', async () => {
        localStorage.setItem('atlas.auth.token', '1|a');
        useNotificationsStore().setUnread(4);
        me.mockRejectedValue(Object.assign(new Error('Unauthenticated'), { status: 401 }));
        await expect(useAuthStore().fetchMe()).rejects.toThrow();
        expect(useNotificationsStore().unread).toBe(0);
    });

    it('/auth/me answering for a different user drops the previous user\'s inbox', async () => {
        localStorage.setItem('atlas.auth.token', '1|a');
        const auth = useAuthStore();
        me.mockResolvedValueOnce({ id: 1, roles: ['student'], unread_count: 3 });
        await auth.fetchMe();
        useNotificationsStore().items = [{ id: 'mine', read_at: null }];
        me.mockResolvedValueOnce({ id: 2, roles: ['teacher'], unread_count: 0 });
        await auth.fetchMe();
        expect(useNotificationsStore().items).toEqual([]);
        expect(useNotificationsStore().unread).toBe(0);
    });
});
