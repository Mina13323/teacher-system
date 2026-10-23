import { defineStore } from 'pinia';
import { auth as authApi } from '@/api';
import { ApiError, setAuthToken } from '@/api/client';
import { useNotificationsStore } from '@/stores/notifications';

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
        // Why the last sign-in ended without the user logging out
        // ('session_transferred' or 'session_ended'); shown on the login page.
        endedReason: null,
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
        applyAuth({ user, token, unread_count: unreadCount }) {
            // A new sign-in never shows the previous user's badge or inbox.
            const notifications = useNotificationsStore();
            notifications.reset();
            notifications.setUnread(unreadCount);
            this.user = user;
            this.token = token;
            this.booted = true;
            this.endedReason = null;
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
        /**
         * Confirms signing in on this device for an account limited to one
         * device (the login answered 409 session_transfer_required). The
         * server signs the other device out.
         */
        async transferSession(transferToken) {
            this.loading = true;
            try {
                const res = await authApi.transferSession({ transfer_token: transferToken });
                this.applyAuth(res);
                return res;
            } finally {
                this.loading = false;
            }
        },
        /**
         * Forgotten password: student code + phone + recovery code. When they
         * match, the student is signed in for the forced password change.
         */
        async recover(payload) {
            this.loading = true;
            try {
                const res = await authApi.recover(payload);
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
         * `booted` stays false so the next navigation asks again. Any other
         * answer (a 403/404 from a proxy, an HTML page instead of JSON) says
         * nothing about the token either: it is kept and the retry screen is
         * shown, rather than signing the user out.
         */
        async fetchMe({ wait = defaultWait, random = Math.random } = {}) {
            if (!this.token) return null;
            setAuthToken(this.token);
            for (let attempt = 0; ; attempt++) {
                try {
                    const res = await authApi.me();
                    if (!res || typeof res !== 'object' || res.id === undefined || res.id === null) {
                        throw new ApiError(ApiError.friendly(0), { status: 0 });
                    }
                    if (this.user && res?.id !== this.user.id) useNotificationsStore().reset();
                    this.user = res;
                    // The badge count rides on /auth/me (no separate request).
                    useNotificationsStore().setUnread(res?.unread_count);
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
                    if (e?.status === 401) {
                        // The token is no longer valid (revoked or expired).
                        this.clear();
                        this.endedReason = e?.data?.code === 'session_transferred' ? 'session_transferred' : null;
                        this.booted = true;
                        throw e;
                    }
                    this.bootError = e;
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
            if (this.user) this.user = { ...this.user, password_recovery: false };
            // A student may now owe a (new) recovery code; the server knows.
            try {
                await this.fetchMe();
            } catch {
                /* the next navigation asks again */
            }
            return result;
        },
        /**
         * A request with the stored token was answered 401 mid-session: the
         * sign-in was revoked or expired. Forget it locally (nothing else on
         * this device is cleared; an exam's local recovery copy stays) and
         * remember why. Returns false when there was no sign-in to end.
         */
        handleUnauthorized(error) {
            if (!this.token) return false;
            this.clear();
            this.endedReason = error?.data?.code === 'session_transferred' ? 'session_transferred' : 'session_ended';
            this.booted = true;
            return true;
        },
        /** The student saved the code shown by the recovery-code dialog. */
        markRecoveryCodeSaved() {
            if (this.user) this.user = { ...this.user, recovery_code_pending: false };
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
            useNotificationsStore().reset();
            this.user = null;
            this.profile = null;
            this.token = null;
            this.bootError = null;
            this.endedReason = null;
            setAuthToken(null);
            try {
                localStorage.removeItem(TOKEN_KEY);
            } catch {
                /* ignore */
            }
        },
    },
});
