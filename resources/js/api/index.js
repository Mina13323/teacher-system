import api from './client';

/**
 * Normalize a "list" payload returned by cross the API's `data` slot.
 * Backend list responses are either a Laravel paginator ({ data: [...], meta })
 * or a resource collection ({ data: [...] }). This always yields { items, meta }.
 */
export function toList(res, extraMeta = {}) {
    if (Array.isArray(res)) {
        return { items: res, meta: null, ...extraMeta };
    }

    if (res && Array.isArray(res.data)) {
        return { items: res.data, meta: res.meta || null, ...extraMeta };
    }

    return { items: [], meta: null, ...extraMeta };
}

// ---- Auth -----------------------------------------------------------------
export const auth = {
    me: () => api.get('/auth/me'),
    login: (payload) => api.post('/auth/login', payload),
    register: (payload) => api.post('/auth/register', payload),
    logout: () => api.post('/auth/logout'),
    profile: () => api.get('/auth/profile'),
    updateProfile: (payload) => api.put('/auth/profile', payload),
    changePassword: (payload) => api.put('/auth/password', payload),
};

// ---- Public courses -------------------------------------------------------
export const publicCatalog = {
    courses: (params) => api.get('/courses', params),
    course: (id) => api.get(`/courses/${id}`),
};

// ---- Notifications (any authenticated user) --------------------------------
export const notifications = {
    all: (params) => api.get('/notifications', params),
    unreadCount: () => api.get('/notifications/unread-count'),
    markRead: (id) => api.post(`/notifications/${id}/read`),
    markAllRead: () => api.post('/notifications/read-all'),
};

// ---- Student --------------------------------------------------------------
export const student = {
    dashboard: () => api.get('/student/dashboard'),
    courses: (params) => api.get('/student/courses', params),
    enroll: (courseId) => api.post(`/student/courses/${courseId}/enroll`),
    course: (courseId) => api.get(`/student/courses/${courseId}`),
    roadmap: (courseId) => api.get(`/student/courses/${courseId}/roadmap`),
    progress: () => api.get('/student/progress'),
    lessonProgress: (lessonId) => api.get(`/student/lessons/${lessonId}/progress`),
    saveLessonProgress: (lessonId, payload) => api.put(`/student/lessons/${lessonId}/progress`, payload),
    lessonVideos: (lessonId) => api.get(`/student/lessons/${lessonId}/videos`),
    playback: (videoId) => api.get(`/student/videos/${videoId}/playback`),
    playbackEvent: (videoId, payload) => api.post(`/student/videos/${videoId}/playback/events`, payload),
    exams: (params) => api.get('/student/exams', params),
    exam: (examId) => api.get(`/student/exams/${examId}`),
    examAttempts: (examId) => api.get(`/student/exams/${examId}/attempts`),
    startExam: (examId) => api.post(`/student/exams/${examId}/start`),
    attempt: (attemptId) => api.get(`/student/attempts/${attemptId}`),
    answer: (attemptId, payload) => api.post(`/student/attempts/${attemptId}/answers`, payload),
    submit: (attemptId) => api.post(`/student/attempts/${attemptId}/submit`),
    recordIntegrity: (attemptId, payload) => api.post(`/student/attempts/${attemptId}/integrity-events`, payload),
    competitions: () => api.get('/student/competitions'),
    competition: (id) => api.get(`/student/competitions/${id}`),
    joinCompetition: (id) => api.post(`/student/competitions/${id}/join`),
    competitionLeaderboard: (id, params) => api.get(`/student/competitions/${id}/leaderboard`, params),
    competitionMe: (id) => api.get(`/student/competitions/${id}/leaderboard/me`),
    analytics: () => api.get('/student/analytics/me'),
};

// ---- Teacher --------------------------------------------------------------
export const teacher = {
    dashboard: () => api.get('/teacher/dashboard'),

    students: (params) => api.get('/teacher/students', params),
    student: (id) => api.get(`/teacher/students/${id}`),
    createStudent: (payload) => api.post('/teacher/students', payload),
    updateStudent: (id, payload) => api.put(`/teacher/students/${id}`, payload),
    activateStudent: (id) => api.patch(`/teacher/students/${id}/activate`),
    deactivateStudent: (id) => api.patch(`/teacher/students/${id}/deactivate`),
    resetStudentPassword: (id, payload) => api.post(`/teacher/students/${id}/reset-password`, payload),
    notifyStudent: (id, payload) => api.post(`/teacher/students/${id}/notify`, payload),

    assistants: (params) => api.get('/teacher/assistants', params),
    assistant: (id) => api.get(`/teacher/assistants/${id}`),
    createAssistant: (payload) => api.post('/teacher/assistants', payload),
    updateAssistant: (id, payload) => api.put(`/teacher/assistants/${id}`, payload),
    activateAssistant: (id) => api.patch(`/teacher/assistants/${id}/activate`),
    deactivateAssistant: (id) => api.patch(`/teacher/assistants/${id}/deactivate`),
    resetAssistantPassword: (id, payload) => api.post(`/teacher/assistants/${id}/reset-password`, payload),

    courses: (params) => api.get('/teacher/courses', params),
    course: (id) => api.get(`/teacher/courses/${id}`),
    createCourse: (payload) => api.post('/teacher/courses', payload),
    updateCourse: (id, payload) => api.put(`/teacher/courses/${id}`, payload),
    publishCourse: (id) => api.patch(`/teacher/courses/${id}/publish`),
    unpublishCourse: (id) => api.patch(`/teacher/courses/${id}/unpublish`),
    deleteCourse: (id) => api.delete(`/teacher/courses/${id}`),

    units: (courseId) => api.get(`/teacher/courses/${courseId}/units`),
    createUnit: (courseId, payload) => api.post(`/teacher/courses/${courseId}/units`, payload),
    updateUnit: (id, payload) => api.put(`/teacher/units/${id}`, payload),
    deleteUnit: (id) => api.delete(`/teacher/units/${id}`),
    reorderUnits: (courseId, orderedIds) => api.put(`/teacher/courses/${courseId}/units/reorder`, { ordered_ids: orderedIds }),

    lessons: (unitId) => api.get(`/teacher/units/${unitId}/lessons`),
    lesson: (id) => api.get(`/teacher/lessons/${id}`),
    createLesson: (unitId, payload) => api.post(`/teacher/units/${unitId}/lessons`, payload),
    updateLesson: (id, payload) => api.put(`/teacher/lessons/${id}`, payload),
    publishLesson: (id) => api.patch(`/teacher/lessons/${id}/publish`),
    unpublishLesson: (id) => api.patch(`/teacher/lessons/${id}/unpublish`),
    deleteLesson: (id) => api.delete(`/teacher/lessons/${id}`),
    reorderLessons: (unitId, orderedIds) => api.put(`/teacher/units/${unitId}/lessons/reorder`, { ordered_ids: orderedIds }),

    videos: (lessonId) => api.get(`/teacher/lessons/${lessonId}/videos`),
    video: (id) => api.get(`/teacher/videos/${id}`),
    createVideo: (lessonId, payload) => api.post(`/teacher/lessons/${lessonId}/videos`, payload),
    updateVideo: (id, payload) => api.put(`/teacher/videos/${id}`, payload),
    publishVideo: (id) => api.patch(`/teacher/videos/${id}/publish`),
    unpublishVideo: (id) => api.patch(`/teacher/videos/${id}/unpublish`),
    deleteVideo: (id) => api.delete(`/teacher/videos/${id}`),
    reorderVideos: (lessonId, orderedIds) => api.put(`/teacher/lessons/${lessonId}/videos/reorder`, { ordered_ids: orderedIds }),

    exams: (courseId, params) => api.get(`/teacher/courses/${courseId}/exams`, params),
    exam: (id) => api.get(`/teacher/exams/${id}`),
    createExam: (courseId, payload) => api.post(`/teacher/courses/${courseId}/exams`, payload),
    updateExam: (id, payload) => api.put(`/teacher/exams/${id}`, payload),
    publishExam: (id) => api.post(`/teacher/exams/${id}/publish`),
    archiveExam: (id) => api.post(`/teacher/exams/${id}/archive`),
    deleteExam: (id) => api.delete(`/teacher/exams/${id}`),
    examAttempts: (examId, params) => api.get(`/teacher/exams/${examId}/attempts`, params),
    attempt: (id) => api.get(`/teacher/attempts/${id}`),

    questions: (examId) => api.get(`/teacher/exams/${examId}/questions`),
    question: (id) => api.get(`/teacher/questions/${id}`),
    createQuestion: (examId, payload) => api.post(`/teacher/exams/${examId}/questions`, payload),
    updateQuestion: (id, payload) => api.put(`/teacher/questions/${id}`, payload),
    deleteQuestion: (id) => api.delete(`/teacher/questions/${id}`),
    options: (questionId) => api.get(`/teacher/questions/${questionId}/options`),
    createOption: (questionId, payload) => api.post(`/teacher/questions/${questionId}/options`, payload),
    updateOption: (id, payload) => api.put(`/teacher/options/${id}`, payload),
    deleteOption: (id) => api.delete(`/teacher/options/${id}`),

    integritySettings: (examId) => api.get(`/teacher/exams/${examId}/integrity`),
    updateIntegritySettings: (examId, payload) => api.put(`/teacher/exams/${examId}/integrity`, payload),
    attemptIntegrity: (attemptId) => api.get(`/teacher/attempts/${attemptId}/integrity`),
    attemptIntegrityEvents: (attemptId) => api.get(`/teacher/attempts/${attemptId}/integrity-events`),
    reviewAttempt: (attemptId, payload) => api.post(`/teacher/attempts/${attemptId}/integrity/review`, payload),

    competitions: (params) => api.get('/teacher/competitions', params),
    competition: (id) => api.get(`/teacher/competitions/${id}`),
    createCompetition: (payload) => api.post('/teacher/competitions', payload),
    updateCompetition: (id, payload) => api.put(`/teacher/competitions/${id}`, payload),
    publishCompetition: (id) => api.post(`/teacher/competitions/${id}/publish`),
    archiveCompetition: (id) => api.post(`/teacher/competitions/${id}/archive`),
    deleteCompetition: (id) => api.delete(`/teacher/competitions/${id}`),
    competitionParticipants: (id, params) => api.get(`/teacher/competitions/${id}/participants`, params),
    competitionLeaderboard: (id, params) => api.get(`/teacher/competitions/${id}/leaderboard`, params),
    recalculateLeaderboard: (id) => api.post(`/teacher/competitions/${id}/recalculate-leaderboard`),
    disqualifyParticipant: (id, participantId) => api.post(`/teacher/competitions/${id}/participants/${participantId}/disqualify`),

    courseStudents: (courseId, params) => api.get(`/teacher/courses/${courseId}/students`, params),
    enrollStudent: (courseId, studentId) => api.post(`/teacher/courses/${courseId}/students`, { student_id: studentId }),
    unenrollStudent: (courseId, studentId) => api.delete(`/teacher/courses/${courseId}/students/${studentId}`),

    analyticsOverview: () => api.get('/teacher/analytics/overview'),
    courseAnalytics: (courseId) => api.get(`/teacher/analytics/courses/${courseId}`),
    studentAnalytics: (studentId) => api.get(`/teacher/analytics/students/${studentId}`),
};

// ---- Admin ----------------------------------------------------------------
export const admin = {
    dashboard: () => api.get('/admin/dashboard'),
    teachers: (params) => api.get('/admin/teachers', params),
    teacher: (id) => api.get(`/admin/teachers/${id}`),
    createTeacher: (payload) => api.post('/admin/teachers', payload),
    updateTeacher: (id, payload) => api.put(`/admin/teachers/${id}`, payload),
    activateTeacher: (id) => api.patch(`/admin/teachers/${id}/activate`),
    deactivateTeacher: (id) => api.patch(`/admin/teachers/${id}/deactivate`),
    resetTeacherPassword: (id, payload) => api.post(`/admin/teachers/${id}/reset-password`, payload),
    students: (params) => api.get('/admin/students', params),
    student: (id) => api.get(`/admin/students/${id}`),
    updateStudent: (id, payload) => api.put(`/admin/students/${id}`, payload),
    activateStudent: (id) => api.patch(`/admin/students/${id}/activate`),
    deactivateStudent: (id) => api.patch(`/admin/students/${id}/deactivate`),
    resetStudentPassword: (id, payload) => api.post(`/admin/students/${id}/reset-password`, payload),
    studentAnalytics: (id) => api.get(`/admin/students/${id}/analytics`),
};
