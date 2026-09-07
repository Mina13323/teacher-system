import { defineStore } from 'pinia';
import { auth as authApi } from '@/api';
import { setAuthToken } from '@/api/client';

const TOKEN_KEY = 'atlas.auth.token';

function readToken() {
    try {
        return localStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: readToken(),
        user: null,
        profile: null,
        booted: false,
        loading: false,
    }),
    getters: {
        isAuthenticated: (s) => Boolean(s.token),
        roles: (s) => s.user?.roles || [],
        isAdmin: (s) => s.user?.roles?.includes('admin'),
        isTeacher: (s) => s.user?.roles?.includes('teacher'),
        isAssistant: (s) => s.user?.roles?.includes('assistant'),
        isStudent: (s) => s.user?.roles?.includes('student'),
        role: (s) => s.user?.roles?.[0] || null,
        displayName: (s) => s.user?.name || 'User',
    },
    actions: {
        applyAuth({ user, token }) {
            this.user = user;
            this.token = token;
            setAuthToken(token);
            try {
                localStorage.setItem(TOKEN_KEY, token);
            } catch {
                /* storage unavailable */
            }
        },
        async login(payload) {
            this.loading = true;
            try {
                const res = await authApi.login(payload);
                this.applyAuth(res);
                return res;
            } finally {
                this.loading = false;
            }
        },
        async register(payload) {
            this.loading = true;
            try {
                const res = await authApi.register(payload);
                this.applyAuth(res);
                return res;
            } finally {
                this.loading = false;
            }
        },
        async fetchMe() {
            if (!this.token) return null;
            try {
                setAuthToken(this.token);
                const res = await authApi.me();
                this.user = res;
                this.booted = true;
                return res;
            } catch (e) {
                this.clear();
                throw e;
            } finally {
                this.booted = true;
            }
        },
        async loadProfile() {
            if (!this.token) return null;
            setAuthToken(this.token);
            const res = await authApi.profile();
            this.profile = res;
            return res;
        },
        async updateProfile(payload) {
            this.profile = await authApi.updateProfile(payload);
            this.user = { ...this.user, ...this.profile };
            return this.profile;
        },
        async changePassword(payload) {
            return authApi.changePassword(payload);
        },
        async logout() {
            try {
                if (this.token) await authApi.logout();
            } catch {
                /* ignore */
            }
            this.clear();
        },
        clear() {
            this.user = null;
            this.profile = null;
            this.token = null;
            setAuthToken(null);
            try {
                localStorage.removeItem(TOKEN_KEY);
            } catch {
                /* ignore */
            }
        },
    },
});
