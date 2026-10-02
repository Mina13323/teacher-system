export function profilePathFor(roles = []) {
    if (roles.includes('admin')) return '/admin/profile';
    if (roles.includes('teacher')) return '/teacher/profile';
    if (roles.includes('assistant')) return '/assistant/profile';
    if (roles.includes('student')) return '/student/profile';
    return '/login';
}
