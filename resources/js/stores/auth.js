import { defineStore } from 'pinia';
import { auth as authApi } from '@/api';
import { setAuthToken } from '@/api/client';

const TOKEN_KEY = 'atlas.auth.token';

/**
 * Waits before re-asking /auth/me after a transient failure. Two short,
 * jittered retries ride out a brief server or database hiccup without
 * sending a burst of identical requests from every open tab.
 */
export const ME_RETRY_DELAYS_MS = [1000, 2500];
const ME_RETRY_JITTER_RATIO = 0.3;

/**
 * Failures that say nothing about the token: no response, a timeout, rate
 * limiting or a server-side error (500, 502, 503, 504).
 */
export function isTransientAuthFailure(error) {
    if (!error) return false;
    return Boolean(error.isNetwork || error.isTimeout || error.isRateLimited || error.isServer);
}

function defaultWait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function jittered(ms, random) {
    return Math.round(ms * (1 - ME_RETRY_JITTER_RATIO + 2 * ME_RETRY_JITTER_RATIO * random()));
}

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
        // Set when /auth/me failed for a reason other than a rejected token.
        // The token is kept; the router shows a retry screen instead of login.
        bootError: null,
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
        canAccessLessons: (s) => Boolean(s.user?.can_access_lessons ?? true),
        canTakeExams: (s) => Boolean(s.user?.can_take_exams ?? true),
        canJoinCompetitions: (s) => Boolean(s.user?.can_join_competitions ?? true),
        studentCode: (s) => s.user?.student_code || null,
        academicYear: (s) => s.user?.academic_year || null,
        academicSubject: (s) => s.user?.academic_subject || null,
        academicSubjectLabel: (s) => s.user?.academic_subject_label || null,
        accessStatus: (s) => s.user?.access_status || 'active',
        isSuspended: (s) => s.user?.access_status === 'suspended' || s.user?.is_active === false,
        isRenewalDue: (s) => s.user?.access_status === 'due',
    },
    actions: {
        applyAuth({ user, token }) {
            this.user = user;
            this.token = token;
            this.booted = true;
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
        /**
         * Loads the signed-in user. Only a 401 signs the user out. A network
         * error, timeout, 429 or 5xx is retried twice with jittered backoff;
         * if it still fails the token is kept, `bootError` is set and
         * `booted` stays false so the next navigation asks again.
         */
        async fetchMe({ wait = defaultWait, random = Math.random } = {}) {
            if (!this.token) return null;
            setAuthToken(this.token);
            for (let attempt = 0; ; attempt++) {
                try {
                    const res = await authApi.me();
                    this.user = res;
                    this.bootError = null;
                    this.booted = true;
                    return res;
                } catch (e) {
                    if (isTransientAuthFailure(e)) {
                        if (attempt < ME_RETRY_DELAYS_MS.length) {
                            await wait(jittered(ME_RETRY_DELAYS_MS[attempt], random));
                            continue;
                        }
                        this.bootError = e;
                        throw e;
                    }
                    // A 401 means the token is no longer valid. Any other
                    // definitive refusal keeps the previous behaviour.
                    this.clear();
                    this.booted = true;
                    throw e;
                }
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
            const result = await authApi.changePassword(payload);
            if (this.user) this.user = { ...this.user, must_change_password: false };
            if (this.profile) this.profile = { ...this.profile, must_change_password: false };
            return result;
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
            this.bootError = null;
            setAuthToken(null);
            try {
                localStorage.removeItem(TOKEN_KEY);
            } catch {
                /* ignore */
            }
        },
    },
});
