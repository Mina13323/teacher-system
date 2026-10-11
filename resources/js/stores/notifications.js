import { defineStore } from 'pinia';
import { notifications as api } from '@/api';
import { toList } from '@/api';

/**
 * Whether finishing an exam can have added a notification: the server sends
 * the "result available" notification in the same request that publishes the
 * result (an exam showing results immediately). Nothing else is sent by a
 * submit or an integrity termination, so the badge needs no refresh otherwise.
 */
export function examResultNotifies(result) {
    return Boolean(result?.grades_published);
}

/** Bumped on every reset, so a count requested for the previous user is dropped. */
let generation = 0;

export const useNotificationsStore = defineStore('notifications', {
    state: () => ({
        items: [],
        meta: null,
        unread: 0,
        loading: false,
        error: null,
    }),
    actions: {
        async fetch(params = {}) {
            this.loading = true;
            this.error = null;
            try {
                const res = await api.all(params);
                const { items, meta } = toList(res);
                this.items = items;
                this.meta = meta;
            } catch (e) {
                this.error = e;
            } finally {
                this.loading = false;
            }
        },
        /**
         * Adopt the unread count the server sent with the signed-in user
         * (GET /auth/me, POST /auth/login). Ignored when the response has none.
         */
        setUnread(count) {
            const n = Number(count);
            if (count === null || count === undefined || !Number.isFinite(n)) return;
            this.unread = Math.max(0, Math.trunc(n));
        },
        async refreshUnread() {
            const gen = generation;
            try {
                const res = await api.unreadCount();
                if (gen !== generation) return this.unread;
                this.unread = res?.unread_count ?? 0;
                return this.unread;
            } catch {
                return 0;
            }
        },
        async markRead(id) {
            await api.markRead(id);
            const n = this.items.find((x) => x.id === id);
            if (n && !n.read_at) {
                n.read_at = new Date().toISOString();
                this.unread = Math.max(0, this.unread - 1);
            }
        },
        async markAllRead() {
            await api.markAllRead();
            this.items.forEach((n) => (n.read_at = n.read_at || new Date().toISOString()));
            this.unread = 0;
        },
        /** Forget everything about the previous user (sign-out, sign-in). */
        reset() {
            generation++;
            this.items = [];
            this.meta = null;
            this.unread = 0;
            this.loading = false;
            this.error = null;
        },
    },
});
