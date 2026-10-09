import api, { sendKeepalive, downloadFile } from './client';

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

export const publicStudentRegistration = {
    details: (token) => api.get(`/public/student-registration/${token}`),
    register: (token, payload) => api.post(`/public/student-registration/${token}`, payload),
};

// ---- Notifications (any authenticated user) --------------------------------
export const notifications = {
    all: (params) => api.get('/notifications', params),
    unreadCount: () => api.get('/notifications/unread-count'),
    markRead: (id) => api.post(`/notifications/${id}/read`),
    markAllRead: () => api.post('/notifications/read-all'),
};

// ---- PHASE 4: lesson Q&A + role-scoped search --------------------------------
export const learning = {
    questions: (lessonId, params) => api.get(`/lessons/${lessonId}/questions`, params),
    ask: (lessonId, payload) => api.post(`/lessons/${lessonId}/questions`, payload),
    reply: (questionId, payload) => api.post(`/lesson-questions/${questionId}/replies`, payload),
    moderate: (questionId) => api.delete(`/lesson-questions/${questionId}`),
    search: (params) => api.get('/search', params),
};

// ---- Web Push (P2) ----------------------------------------------------------
export const push = {
    subscriptions: () => api.get('/push-subscriptions'),
    subscribe: (payload) => api.post('/push-subscriptions', payload),
    unsubscribe: (id) => api.delete(`/push-subscriptions/${id}`),
};

// ---- Student --------------------------------------------------------------
export const student = {
    // Assignments (P2)
    assignments: (params) => api.get('/student/assignments', params),
    assignment: (id) => api.get(`/student/assignments/${id}`),
    submitAssignment: (id, payload) => api.post(`/student/assignments/${id}/submit`, payload),
    downloadSubmissionFile: (submissionId, filename) => downloadFile(`/assignment-submissions/${submissionId}/file`, filename),

    // Certificates (P2)
    certificates: (params) => api.get('/student/certificates', params),
    issueCertificate: (courseId) => api.post(`/student/courses/${courseId}/certificate`),
    courseCertificate: (courseId) => api.get(`/student/courses/${courseId}/certificate`),
    verifyCertificate: (code) => api.get(`/public/certificates/${encodeURIComponent(code)}`),

    // PHASE 4 §32/§33 — private notes + personal bookmarks
    notes: (params) => api.get('/student/notes', params),
    saveNote: (payload) => api.post('/student/notes', payload),
    updateNote: (id, payload) => api.put(`/student/notes/${id}`, payload),
    deleteNote: (id) => api.delete(`/student/notes/${id}`),
    bookmarks: (params) => api.get('/student/bookmarks', params),
    addBookmark: (payload) => api.post('/student/bookmarks', payload),
    deleteBookmark: (id) => api.delete(`/student/bookmarks/${id}`),

    // Notification preferences (P2)
    notificationPreferences: () => api.get('/student/notification-preferences'),
    updateNotificationPreferences: (payload) => api.put('/student/notification-preferences', payload),

    // Lesson attachments download (authorized)
    downloadLessonAttachment: (attachmentId, filename) => downloadFile(`/lesson-attachments/${attachmentId}/file`, filename),

    dashboard: () => api.get('/student/dashboard'),
    courses: (params) => api.get('/student/courses', params),
    enroll: (courseId) => api.post(`/student/courses/${courseId}/enroll`),
    course: (courseId) => api.get(`/student/courses/${courseId}`),
    roadmap: (courseId) => api.get(`/student/courses/${courseId}/roadmap`),
    progress: () => api.get('/student/progress'),
    lessonProgress: (lessonId) => api.get(`/student/lessons/${lessonId}/progress`),
    saveLessonProgress: (lessonId, payload) => api.put(`/student/lessons/${lessonId}/progress`, payload),
    lessonVideos: (lessonId) => api.get(`/student/lessons/${lessonId}/videos`),
    lesson: (lessonId) => api.get(`/student/lessons/${lessonId}`),
    playback: (videoId) => api.get(`/student/videos/${videoId}/playback`),
    playbackEvent: (videoId, payload) => api.post(`/student/videos/${videoId}/playback/events`, payload),
    exams: (params) => api.get('/student/exams', params),
    exam: (examId) => api.get(`/student/exams/${examId}`),
    examAttempts: (examId, requestOptions = {}) => api.get(`/student/exams/${examId}/attempts`, undefined, requestOptions),
    startExam: (examId, payload) => api.post(`/student/exams/${examId}/start`, payload),
    attempt: (attemptId) => api.get(`/student/attempts/${attemptId}`),
    // Answer saves ask for a short acknowledgement of the saved question
    // instead of the whole attempt (see utils/answerAck.js).
    answer: (attemptId, payload) => api.post(`/student/attempts/${attemptId}/answers`, { ...payload, compact_response: true }),
    answerKeepalive: (attemptId, payload = {}) => sendKeepalive(`/student/attempts/${attemptId}/answers`, { ...payload, compact_response: true }),
    submit: (attemptId) => api.post(`/student/attempts/${attemptId}/submit`),
    heartbeat: (attemptId) => api.post(`/student/attempts/${attemptId}/heartbeat`),
    // Database-free server clock (no auth needed); see ServerTimeController.
    serverTime: () => api.get('/time', undefined, { timeout: 10_000 }),
    terminate: (attemptId, payload = {}) => api.post(`/student/attempts/${attemptId}/terminate`, payload),
    terminateKeepalive: (attemptId, payload = {}) => sendKeepalive(`/student/attempts/${attemptId}/terminate`, payload),
    recordIntegrity: (attemptId, payload) => api.post(`/student/attempts/${attemptId}/integrity-events`, payload),
    recordIntegrityKeepalive: (attemptId, payload) => sendKeepalive(`/student/attempts/${attemptId}/integrity-events`, payload),
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
    deactivateStudent: (id) => api.patch(`/teacher/students/${id}/deactivate`),
    batchDeactivateStudents: (ids) => api.post('/teacher/students/batch-deactivate', { ids }),
    activateStudent: (id) => api.patch(`/teacher/students/${id}/activate`),
    resetStudentPassword: (id, payload) => api.post(`/teacher/students/${id}/reset-password`, payload),
    resetStudentCredentials: (id) => api.post(`/teacher/students/${id}/reset-credentials`),
    suspendStudent: (id, payload) => api.post(`/teacher/students/${id}/suspend`, payload),
    restoreStudent: (id, payload) => api.post(`/teacher/students/${id}/restore`, payload),
    allowStudentImmediately: (id, payload) => api.post(`/teacher/students/${id}/allow-immediately`, payload),
    renewStudent: (id, payload) => api.post(`/teacher/students/${id}/renew`, payload),
    notifyStudent: (id, payload) => api.post(`/teacher/students/${id}/notify`, payload),
    studentRegistrationLink: () => api.get('/teacher/student-registration-link'),
    rotateStudentRegistrationLink: () => api.post('/teacher/student-registration-link/rotate'),
    setStudentRegistrationLinkActive: (is_active) => api.patch('/teacher/student-registration-link', { is_active }),

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
    // Attempts needing integrity attention across the teacher's courses, in one request.
    integrityAttempts: () => api.get('/teacher/integrity/attempts'),
    bulkDeleteExamAttempts: (examId, attemptIds, reason = null) => api.post(`/teacher/exams/${examId}/attempts/bulk-delete`, { attempt_ids: attemptIds, confirmed: true, reason }),
    makeUpAssignments: (examId, params) => api.get(`/teacher/exams/${examId}/make-up-assignments`, params),
    assignExamMakeUps: (examId, studentIds, reason = null) => api.post(`/teacher/exams/${examId}/make-up-assignments`, { student_ids: studentIds, reason }),
    revokeExamMakeUp: (examId, assignmentId) => api.delete(`/teacher/exams/${examId}/make-up-assignments/${assignmentId}`),
    attempt: (id) => api.get(`/teacher/attempts/${id}`),
    gradeEssay: (attemptId, payload) => api.post(`/teacher/attempts/${attemptId}/grade-essay`, payload),
    publishGrades: (attemptId) => api.post(`/teacher/attempts/${attemptId}/publish-grades`),

    questions: (examId) => api.get(`/teacher/exams/${examId}/questions`),
    question: (id) => api.get(`/teacher/questions/${id}`),
    createQuestion: (examId, payload) => api.post(`/teacher/exams/${examId}/questions`, payload),
    updateQuestion: (id, payload) => api.put(`/teacher/questions/${id}`, payload),
    uploadQuestionImage: (id, image) => { const form = new FormData(); form.append('image', image); return api.post(`/teacher/questions/${id}/image`, form); },
    removeQuestionImage: (id) => api.delete(`/teacher/questions/${id}/image`),
    deleteQuestion: (id) => api.delete(`/teacher/questions/${id}`),
    options: (questionId) => api.get(`/teacher/questions/${questionId}/options`),
    createOption: (questionId, payload) => api.post(`/teacher/questions/${questionId}/options`, payload),
    updateOption: (id, payload) => api.put(`/teacher/options/${id}`, payload),
    deleteOption: (id) => api.delete(`/teacher/options/${id}`),
    // Regrading can walk a full exam cohort; allow this explicit staff action
    // longer than the default interactive request timeout.
    regradeQuestionAttempts: (questionId, payload) => api.post(`/teacher/questions/${questionId}/regrade-submitted-attempts`, payload, { timeout: 120_000 }),

    // Exam templates — reusable question structures (counts and marks only)
    examTemplates: () => api.get('/teacher/exam-templates'),
    applyExamTemplate: (examId, templateId) => api.post(`/teacher/exams/${examId}/apply-template`, { template_id: templateId }),
    saveExamAsTemplate: (examId, payload) => api.post(`/teacher/exams/${examId}/save-as-template`, payload),
    deleteExamTemplate: (id) => api.delete(`/teacher/exam-templates/${id}`),

    // Bulk authoring — write the whole paper in one request
    syncQuestions: (examId, questions) => api.put(`/teacher/exams/${examId}/questions`, { questions }),

    integritySettings: (examId) => api.get(`/teacher/exams/${examId}/integrity`),
    updateIntegritySettings: (examId, payload) => api.put(`/teacher/exams/${examId}/integrity`, payload),
    attemptIntegrity: (attemptId) => api.get(`/teacher/attempts/${attemptId}/integrity`),
    attemptIntegrityEvents: (attemptId, params) => api.get(`/teacher/attempts/${attemptId}/integrity-events`, params),
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
    enrollAcademicYear: (courseId, academicYear) => api.post(`/teacher/courses/${courseId}/students`, { academic_year: academicYear }),
    unenrollStudent: (courseId, studentId) => api.delete(`/teacher/courses/${courseId}/students/${studentId}`),

    // Attempts grouped by student (P1 attempt-management UX)
    attemptsGrouped: (examId, params) => api.get(`/teacher/exams/${examId}/attempts/grouped`, params),
    exportResults: (examId, format = 'csv') => {
        const ext = { csv: 'csv', xlsx: 'xlsx', pdf: 'pdf', print: 'html' }[format] || 'csv';
        return downloadFile(`/teacher/exams/${examId}/results/export?format=${format}`, `exam-${examId}-results.${ext}`);
    },

    // Bulk student import (CSV or XLSX -> validate -> preview -> confirm -> report)
    importStudentsPreview: (input) => api.post('/teacher/students/import/preview', typeof input === 'string' ? { csv: input } : input),
    importStudentsConfirm: (input) => api.post('/teacher/students/import/confirm', typeof input === 'string' ? { csv: input } : input),

    // Assignments (P2)
    courseAssignments: (courseId, params) => api.get(`/teacher/courses/${courseId}/assignments`, params),
    createAssignment: (courseId, payload) => api.post(`/teacher/courses/${courseId}/assignments`, payload),
    assignment: (id) => api.get(`/teacher/assignments/${id}`),
    updateAssignment: (id, payload) => api.put(`/teacher/assignments/${id}`, payload),
    publishAssignment: (id) => api.post(`/teacher/assignments/${id}/publish`),
    unpublishAssignment: (id) => api.post(`/teacher/assignments/${id}/unpublish`),
    deleteAssignment: (id) => api.delete(`/teacher/assignments/${id}`),
    assignmentSubmissions: (id, params) => api.get(`/teacher/assignments/${id}/submissions`, params),
    gradeAssignment: (submissionId, payload) => api.post(`/teacher/assignment-submissions/${submissionId}/grade`, payload),

    // Lesson attachments (P2)
    lessonAttachments: (lessonId) => api.get(`/teacher/lessons/${lessonId}/attachments`),
    uploadLessonAttachment: (lessonId, form) => api.post(`/teacher/lessons/${lessonId}/attachments`, form),
    updateLessonAttachment: (id, payload) => api.put(`/teacher/lesson-attachments/${id}`, payload),
    deleteLessonAttachment: (id) => api.delete(`/teacher/lesson-attachments/${id}`),

    // Staff audit trail (admin-only endpoint, kept here for discoverability)
    auditLogs: (params) => api.get('/admin/audit-logs', params),

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
    anonymizeStudent: (id, confirmation) => api.post(`/admin/students/${id}/anonymize`, { confirmation }),
    forceDeleteStudent: (id, payload) => api.post(`/admin/students/${id}/force-delete`, payload),
    activateStudent: (id) => api.patch(`/admin/students/${id}/activate`),
    deactivateStudent: (id) => api.patch(`/admin/students/${id}/deactivate`),
    resetStudentPassword: (id, payload) => api.post(`/admin/students/${id}/reset-password`, payload),
    studentAnalytics: (id) => api.get(`/admin/students/${id}/analytics`),
};
