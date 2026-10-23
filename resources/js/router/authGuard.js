import { profilePathFor } from '@/utils/authRoutes';

export function homeFor(roles = []) {
    if (roles.includes('admin')) return '/admin';
    if (roles.includes('teacher')) return '/teacher';
    if (roles.includes('assistant')) return '/assistant';
    if (roles.includes('student')) return '/student';
    return '/';
}

/**
 * The global navigation guard, given the auth store. Kept out of index.js so
 * it can be tested without a browser history.
 */
export async function authGuard(to, auth) {
    // A failed boot check sends the user to the reconnect screen. That redirect
    // passes through this guard again, and checking again here would repeat
    // the whole failed sequence before the screen even shows. The screen's
    // Retry button asks explicitly; any other navigation still checks.
    const arrivingAfterFailedCheck = to.name === 'reconnect' && auth.bootError;

    if (!auth.booted && !arrivingAfterFailedCheck) {
        try {
            await auth.fetchMe();
        } catch {
            /* handled below */
        }
    }

    if (auth.user?.must_change_password && !to.meta.public) {
        const profilePath = profilePathFor(auth.roles);
        if (to.path !== profilePath) return profilePath;
    }

    if (to.meta.public) {
        return true;
    }

    // The token is still stored but /auth/me could not be reached (network,
    // timeout or a 5xx). Without the user we cannot pick a role-specific page,
    // and the failure does not mean the token is invalid, so wait on a retry
    // screen instead of sending the user to the login page.
    if (auth.isAuthenticated && !auth.user && auth.bootError) {
        return { name: 'reconnect', query: { redirect: to.fullPath } };
    }

    if (to.meta.guest) {
        if (auth.isAuthenticated) return homeFor(auth.roles);
        return true;
    }

    if (!auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    const allowed = to.meta.roles || [];
    if (allowed.length === 0) return true;

    const ok = allowed.some((r) => auth.roles.includes(r));
    if (!ok) return homeFor(auth.roles);

    return true;
}
