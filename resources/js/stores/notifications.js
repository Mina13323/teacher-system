import { defineStore } from 'pinia';
import { notifications as api } from '@/api';
import { toList } from '@/api';

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
        async refreshUnread() {
            try {
                const res = await api.unreadCount();
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
    },
});
