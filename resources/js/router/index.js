import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const routes = [
    { path: '/', name: 'home', component: () => import('@/views/Public/Home.vue') },
    { path: '/courses', name: 'public-courses', component: () => import('@/views/Public/CourseCatalog.vue') },
    { path: '/courses/:id', name: 'public-course', component: () => import('@/views/Public/CourseShow.vue') },
    { path: '/login', name: 'login', component: () => import('@/views/Auth/Login.vue'), meta: { guest: true } },
    { path: '/register', name: 'register', component: () => import('@/views/Auth/Register.vue'), meta: { guest: true } },

    // ---- Student -----------------------------------------------------------
    {
        path: '/student',
        component: () => import('@/layouts/StudentLayout.vue'),
        meta: { roles: ['student'] },
        children: [
            { path: '', name: 'student.dashboard', component: () => import('@/views/Student/Dashboard.vue') },
            { path: 'courses', name: 'student.courses', component: () => import('@/views/Student/MyCourses.vue') },
            { path: 'courses/:id', name: 'student.course', component: () => import('@/views/Student/CourseShow.vue') },
            { path: 'lessons/:id', name: 'student.lesson', component: () => import('@/views/Student/Lesson.vue') },
            { path: 'exams', name: 'student.exams', component: () => import('@/views/Student/Exams.vue') },
            { path: 'exams/:id', name: 'student.exam', component: () => import('@/views/Student/ExamShow.vue') },
            { path: 'exams/:id/start', name: 'student.exam.start', component: () => import('@/views/Student/ExamTake.vue') },
            { path: 'attempts/:id', name: 'student.attempt', component: () => import('@/views/Student/ExamTake.vue') },
            { path: 'competitions', name: 'student.competitions', component: () => import('@/views/Student/Competitions.vue') },
            { path: 'competitions/:id', name: 'student.competition', component: () => import('@/views/Student/CompetitionShow.vue') },
            { path: 'analytics', name: 'student.analytics', component: () => import('@/views/Student/Analytics.vue') },
            { path: 'notifications', name: 'student.notifications', component: () => import('@/views/Shared/Notifications.vue') },
            { path: 'profile', name: 'student.profile', component: () => import('@/views/Shared/Profile.vue') },
        ],
    },

    // ---- Teacher -----------------------------------------------------------
    {
        path: '/teacher',
        component: () => import('@/layouts/TeacherLayout.vue'),
        meta: { roles: ['teacher', 'admin'] },
        children: [
            { path: '', name: 'teacher.dashboard', component: () => import('@/views/Teacher/Dashboard.vue') },
            { path: 'students', name: 'teacher.students', component: () => import('@/views/Teacher/Students.vue') },
            { path: 'students/new', name: 'teacher.students.new', component: () => import('@/views/Teacher/StudentForm.vue') },
            { path: 'students/:id', name: 'teacher.student', component: () => import('@/views/Teacher/StudentShow.vue') },
            { path: 'students/:id/edit', name: 'teacher.students.edit', component: () => import('@/views/Teacher/StudentForm.vue') },
            { path: 'assistants', name: 'teacher.assistants', component: () => import('@/views/Teacher/Assistants.vue') },
            { path: 'assistants/new', name: 'teacher.assistants.new', component: () => import('@/views/Teacher/AssistantForm.vue') },
            { path: 'assistants/:id/edit', name: 'teacher.assistants.edit', component: () => import('@/views/Teacher/AssistantForm.vue') },
            { path: 'courses', name: 'teacher.courses', component: () => import('@/views/Teacher/Courses.vue') },
            { path: 'courses/new', name: 'teacher.courses.new', component: () => import('@/views/Teacher/CourseForm.vue') },
            { path: 'courses/:id', name: 'teacher.course', component: () => import('@/views/Teacher/CourseDetail.vue') },
            { path: 'courses/:id/edit', name: 'teacher.courses.edit', component: () => import('@/views/Teacher/CourseForm.vue') },
            { path: 'exams/:id', name: 'teacher.exam', component: () => import('@/views/Teacher/ExamDetail.vue') },
            { path: 'exams/:id/edit', name: 'teacher.exams.edit', component: () => import('@/views/Teacher/ExamForm.vue') },
            { path: 'competitions', name: 'teacher.competitions', component: () => import('@/views/Teacher/Competitions.vue') },
            { path: 'competitions/new', name: 'teacher.competitions.new', component: () => import('@/views/Teacher/CompetitionForm.vue') },
            { path: 'competitions/:id', name: 'teacher.competition', component: () => import('@/views/Teacher/CompetitionDetail.vue') },
            { path: 'competitions/:id/edit', name: 'teacher.competitions.edit', component: () => import('@/views/Teacher/CompetitionForm.vue') },
            { path: 'analytics', name: 'teacher.analytics', component: () => import('@/views/Teacher/Analytics.vue') },
            { path: 'analytics/students/:id', name: 'teacher.ans.student', component: () => import('@/views/Teacher/StudentAnalytics.vue') },
            { path: 'integrity', name: 'teacher.integrity', component: () => import('@/views/Teacher/Integrity.vue') },
            { path: 'integrity/attempts/:id', name: 'teacher.integrity.attempt', component: () => import('@/views/Teacher/IntegrityAttempt.vue') },
            { path: 'notifications', name: 'teacher.notifications', component: () => import('@/views/Shared/Notifications.vue') },
            { path: 'profile', name: 'teacher.profile', component: () => import('@/views/Shared/Profile.vue') },
        ],
    },

    // ---- Assistant ---------------------------------------------------------
    {
        path: '/assistant',
        component: () => import('@/layouts/AssistantLayout.vue'),
        meta: { roles: ['assistant'] },
        children: [
            { path: '', name: 'assistant.dashboard', component: () => import('@/views/Assistant/Dashboard.vue') },
            { path: 'students', name: 'assistant.students', component: () => import('@/views/Teacher/Students.vue') },
            { path: 'students/new', name: 'assistant.students.new', component: () => import('@/views/Teacher/StudentForm.vue') },
            { path: 'students/:id', name: 'assistant.student', component: () => import('@/views/Teacher/StudentShow.vue') },
            { path: 'students/:id/edit', name: 'assistant.students.edit', component: () => import('@/views/Teacher/StudentForm.vue') },
            { path: 'enroll', name: 'assistant.enroll', component: () => import('@/views/Assistant/Enroll.vue') },
            { path: 'courses/:id/students', name: 'assistant.course.students', component: () => import('@/views/Assistant/CourseStudents.vue') },
            { path: 'notifications', name: 'assistant.notifications', component: () => import('@/views/Shared/Notifications.vue') },
            { path: 'profile', name: 'assistant.profile', component: () => import('@/views/Shared/Profile.vue') },
        ],
    },

    // ---- Admin -------------------------------------------------------------
    {
        path: '/admin',
        component: () => import('@/layouts/AdminLayout.vue'),
        meta: { roles: ['admin'] },
        children: [
            { path: '', name: 'admin.dashboard', component: () => import('@/views/Admin/Dashboard.vue') },
            { path: 'teachers', name: 'admin.teachers', component: () => import('@/views/Admin/Teachers.vue') },
            { path: 'teachers/new', name: 'admin.teachers.new', component: () => import('@/views/Admin/TeacherForm.vue') },
            { path: 'teachers/:id/edit', name: 'admin.teachers.edit', component: () => import('@/views/Admin/TeacherForm.vue') },
            { path: 'students', name: 'admin.students', component: () => import('@/views/Admin/Students.vue') },
            { path: 'students/:id', name: 'admin.student', component: () => import('@/views/Admin/StudentShow.vue') },
            { path: 'notifications', name: 'admin.notifications', component: () => import('@/views/Shared/Notifications.vue') },
            { path: 'profile', name: 'admin.profile', component: () => import('@/views/Shared/Profile.vue') },
        ],
    },

    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/Shared/NotFound.vue') },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior() {
        return { top: 0 };
    },
});

function homeFor(roles = []) {
    if (roles.includes('admin')) return '/admin';
    if (roles.includes('teacher')) return '/teacher';
    if (roles.includes('assistant')) return '/assistant';
    if (roles.includes('student')) return '/student';
    return '/';
}

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.booted) {
        try {
            await auth.fetchMe();
        } catch {
            /* handled below */
        }
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
});

export default router;
