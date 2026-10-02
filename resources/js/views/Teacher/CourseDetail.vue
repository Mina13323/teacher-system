<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { formatDate } from '@/utils/format';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppModal from '@/components/ui/AppModal.vue';
import StudentSearchSelect from '@/components/ui/StudentSearchSelect.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Icon from '@/components/ui/Icon.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();
const courseId = route.params.id;

const tab = ref('content');
const { loading, error, data, run } = useAsync(async () => {
    const c = await teacher.course(courseId);
    await loadExams();
    await loadStudents();
    return c;
});

const exams = ref([]);
const examsMeta = ref(null);
const students = ref([]);
const studentsMeta = ref(null);

async function loadExams(p = 1) {
    const res = toList(await teacher.exams(courseId, { per_page: 15, page: p }));
    exams.value = res.items;
    examsMeta.value = res.meta;
}
async function loadStudents(p = 1) {
    const res = toList(await teacher.courseStudents(courseId, { per_page: 15, page: p }));
    students.value = res.items;
    studentsMeta.value = res.meta;
}

// `units` (+ each unit's `lessons` + each lesson's `videos`) are nested resource
// collections (`{ data: [...] }`), so normalise to plain arrays.
const units = computed(() => {
    const us = data.value?.units;
    if (!us) return [];
    const arr = Array.isArray(us) ? us : (us.data || []);
    return arr.map((u) => {
        const lessonsRaw = Array.isArray(u.lessons) ? u.lessons : (u.lessons?.data || []);
        return {
            ...u,
            lessons: lessonsRaw.map((l) => ({
                ...l,
                videos: Array.isArray(l.videos) ? l.videos : (l.videos?.data || []),
            })),
        };
    });
});

// ---- Assignments (P2 admin): create, publish, review, grade ----
const assignments = ref([]);
const assignmentsBusy = ref(false);
const assignmentModal = ref(false);
const assignmentBusy = ref(false);
const assignmentErrors = ref({});
const assignmentForm = reactive({ id: null, title: '', description: '', due_at: '', points: 10, is_published: false });

const submissionsModal = ref(false);
const submissions = ref([]);
const submissionsBusy = ref(false);
const submissionsFor = ref(null);

const gradeModal = ref(false);
const gradeBusy = ref(false);
const gradeTarget = ref(null);
const gradeForm = reactive({ score: null, feedback: '' });

const confirmDeleteAssignment = ref(false);
const deleteAssignmentTarget = ref(null);

async function loadAssignments() {
    assignmentsBusy.value = true;
    try {
        const res = await teacher.courseAssignments(courseId);
        assignments.value = res.items || res.data || res || [];
    } catch {
        assignments.value = [];
    } finally {
        assignmentsBusy.value = false;
    }
}

function openAssignment(assignment = null) {
    assignmentForm.id = assignment?.id || null;
    assignmentForm.title = assignment?.title || '';
    assignmentForm.description = assignment?.description || '';
    assignmentForm.due_at = assignment?.due_at ? String(assignment.due_at).slice(0, 16) : '';
    assignmentForm.points = assignment?.points ?? 10;
    assignmentForm.is_published = Boolean(assignment?.is_published);
    assignmentErrors.value = {};
    assignmentModal.value = true;
}

async function saveAssignment() {
    assignmentBusy.value = true;
    assignmentErrors.value = {};
    const payload = {
        title: assignmentForm.title,
        description: assignmentForm.description || null,
        due_at: assignmentForm.due_at || null,
        points: assignmentForm.points,
        is_published: assignmentForm.is_published,
    };
    try {
        if (assignmentForm.id) await teacher.updateAssignment(assignmentForm.id, payload);
        else await teacher.createAssignment(courseId, payload);
        toast.success(assignmentForm.id ? t('assignments.updated') : t('assignments.created'));
        assignmentModal.value = false;
        await loadAssignments();
    } catch (e) {
        assignmentErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        assignmentBusy.value = false;
    }
}

async function togglePublish(a) {
    try {
        if (a.is_published) await teacher.unpublishAssignment(a.id);
        else await teacher.publishAssignment(a.id);
        toast.success(a.is_published ? t('assignments.unpublished') : t('assignments.published'));
        await loadAssignments();
    } catch (e) {
        toast.error(e.message);
    }
}

function askDeleteAssignment(a) {
    deleteAssignmentTarget.value = a;
    confirmDeleteAssignment.value = true;
}

async function deleteAssignment() {
    const a = deleteAssignmentTarget.value;
    if (!a) return;
    try {
        await teacher.deleteAssignment(a.id);
        toast.success(t('assignments.deleted'));
        confirmDeleteAssignment.value = false;
        await loadAssignments();
    } catch (e) {
        toast.error(e.message);
    }
}

async function openSubmissions(a) {
    submissionsFor.value = a;
    submissionsModal.value = true;
    submissionsBusy.value = true;
    try {
        const res = await teacher.assignmentSubmissions(a.id);
        submissions.value = res.items || res.data || res || [];
    } catch (e) {
        toast.error(e.message);
        submissions.value = [];
    } finally {
        submissionsBusy.value = false;
    }
}

function openGrade(sub) {
    gradeTarget.value = sub;
    gradeForm.score = sub.score;
    gradeForm.feedback = sub.feedback || '';
    gradeModal.value = true;
}

async function saveGrade() {
    const sub = gradeTarget.value;
    if (!sub) return;
    gradeBusy.value = true;
    try {
        await teacher.gradeAssignment(sub.id, { score: gradeForm.score, feedback: gradeForm.feedback || null });
        toast.success(t('assignments.graded'));
        gradeModal.value = false;
        if (submissionsFor.value) await openSubmissions(submissionsFor.value);
    } catch (e) {
        toast.error(e.message);
    } finally {
        gradeBusy.value = false;
    }
}

async function downloadSubmissionFile(sub) {
    try {
        const { downloadFile } = await import('@/api/client');
        await downloadFile(`/assignment-submissions/${sub.id}/file`, sub.file?.name || 'submission');
    } catch (e) {
        toast.error(e.message);
    }
}

const tabs = computed(() => [
    { key: 'content', label: t('courses.contentTab') },
    { key: 'assignments', label: t('nav.assignments') },
    { key: 'exams', label: t('courses.examsTab') },
    { key: 'students', label: t('courses.studentsTab') },
]);

async function refresh() {
    await run();
    loadExams(1);
    loadStudents(1);
    loadAssignments();
}

// ---- Unit modal ----
const unitModal = ref(false);
const unitForm = reactive({ id: null, title: '', description: '' });
const unitErrors = ref({});
const unitBusy = ref(false);
function openUnit(unit = null) {
    unitForm.id = unit?.id || null;
    unitForm.title = unit?.title || '';
    unitForm.description = unit?.description || '';
    unitErrors.value = {};
    unitModal.value = true;
}
async function saveUnit() {
    unitBusy.value = true;
    unitErrors.value = {};
    try {
        if (unitForm.id) await teacher.updateUnit(unitForm.id, { title: unitForm.title, description: unitForm.description || null });
        else await teacher.createUnit(courseId, { title: unitForm.title, description: unitForm.description || null });
        toast.success(unitForm.id ? t('courses.unitUpdated') : t('courses.unitCreated'));
        unitModal.value = false;
        refresh();
    } catch (e) {
        unitErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        unitBusy.value = false;
    }
}

// ---- Lesson modal ----
const lessonModal = ref(false);
const lessonForm = reactive({ id: null, unitId: null, title: '', description: '', content: '', is_published: true });
const lessonErrors = ref({});
const lessonBusy = ref(false);
function openLesson(unit, lesson = null) {
    lessonForm.id = lesson?.id || null;
    lessonForm.unitId = unit.id;
    lessonForm.title = lesson?.title || '';
    lessonForm.description = lesson?.description || '';
    lessonForm.content = lesson?.content || '';
    lessonForm.is_published = lesson ? Boolean(lesson.is_published) : true;
    lessonErrors.value = {};
    lessonModal.value = true;
    if (lessonForm.id) loadLessonAttachments(lessonForm.id);
    else lessonAttachments.value = [];
}
async function saveLesson() {
    lessonBusy.value = true;
    lessonErrors.value = {};
    const payload = { title: lessonForm.title, description: lessonForm.description || null, content: lessonForm.content || null, is_published: lessonForm.is_published };
    try {
        if (lessonForm.id) await teacher.updateLesson(lessonForm.id, payload);
        else await teacher.createLesson(lessonForm.unitId, payload);
        toast.success(lessonForm.id ? t('courses.lessonUpdated') : t('courses.lessonCreated'));
        lessonModal.value = false;
        refresh();
    } catch (e) {
        lessonErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        lessonBusy.value = false;
    }
}

// ---- Lesson attachments (P2): private files students can download ----
const lessonAttachments = ref([]);
const attachmentBusy = ref(false);

async function loadLessonAttachments(lessonId) {
    try {
        const res = await teacher.lessonAttachments(lessonId);
        lessonAttachments.value = res.items || res.data || res || [];
    } catch {
        lessonAttachments.value = [];
    }
}

function onAttachmentFile(e) {
    const f = e.target.files?.[0];
    if (!f || !lessonForm.id) return;
    uploadAttachment(f);
    e.target.value = '';
}

async function uploadAttachment(file) {
    attachmentBusy.value = true;
    try {
        const form = new FormData();
        form.append('file', file);
        form.append('title', file.name);
        await teacher.uploadLessonAttachment(lessonForm.id, form);
        toast.success(t('courses.attachmentUploaded'));
        await loadLessonAttachments(lessonForm.id);
    } catch (e) {
        toast.error(e.message);
    } finally {
        attachmentBusy.value = false;
    }
}

async function deleteAttachment(id) {
    attachmentBusy.value = true;
    try {
        await teacher.deleteLessonAttachment(id);
        toast.success(t('courses.attachmentDeleted'));
        await loadLessonAttachments(lessonForm.id);
    } catch (e) {
        toast.error(e.message);
    } finally {
        attachmentBusy.value = false;
    }
}

// ---- Video modal ----
const videoModal = ref(false);
const videoForm = reactive({ id: null, lessonId: null, title: '', provider: 'youtube', provider_video_id: '', storage_path: '', duration: 0, is_published: true });
const videoErrors = ref({});
const videoBusy = ref(false);
const providerOptions = computed(() => [
    { value: 'youtube', label: t('courses.youtube') },
    { value: 'storage', label: t('courses.storage') },
]);
function openVideo(lesson, video = null) {
    videoForm.id = video?.id || null;
    videoForm.lessonId = lesson.id;
    videoForm.title = video?.title || '';
    videoForm.provider = video?.provider || 'youtube';
    videoForm.provider_video_id = video?.provider_video_id || '';
    videoForm.storage_path = video?.storage_path || '';
    videoForm.duration = video?.duration || 0;
    videoForm.is_published = video ? Boolean(video.is_published) : true;
    videoErrors.value = {};
    videoModal.value = true;
}
async function saveVideo() {
    videoBusy.value = true;
    videoErrors.value = {};
    const payload = { title: videoForm.title, provider: videoForm.provider, duration: videoForm.duration || 0, is_published: videoForm.is_published };
    if (videoForm.provider === 'youtube') payload.provider_video_id = videoForm.provider_video_id;
    else payload.storage_path = videoForm.storage_path;
    try {
        if (videoForm.id) await teacher.updateVideo(videoForm.id, payload);
        else await teacher.createVideo(videoForm.lessonId, payload);
        toast.success(videoForm.id ? t('courses.videoUpdated') : t('courses.videoCreated'));
        videoModal.value = false;
        refresh();
    } catch (e) {
        videoErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        videoBusy.value = false;
    }
}

// ---- Delete / publish ----
const confirmTarget = ref(null);
const confirmKind = ref('');
const confirmBusy = ref(false);
function askDelete(target, kind) { confirmTarget.value = target; confirmKind.value = kind; }
const confirmTitle = computed(() => confirmKind.value === 'enrollment' ? t('courses.unenrollTitle') : t('common.confirmDelete'));
const confirmMessage = computed(() => confirmKind.value === 'enrollment'
    ? t('courses.removeStudentMessage')
    : t('courses.deleteKindMessage', { kind: t(`courses.${confirmKind.value}`, confirmKind.value) }));
async function runDelete() {
    confirmBusy.value = true;
    try {
        if (confirmKind.value === 'unit') await teacher.deleteUnit(confirmTarget.value.id);
        if (confirmKind.value === 'lesson') await teacher.deleteLesson(confirmTarget.value.id);
        if (confirmKind.value === 'video') await teacher.deleteVideo(confirmTarget.value.id);
        if (confirmKind.value === 'enrollment') await teacher.unenrollStudent(courseId, confirmTarget.value.student_id);
        toast.success(confirmKind.value === 'enrollment' ? t('students.unenrolled') : t('common.deleted'));
        confirmTarget.value = null;
        refresh();
    } catch (e) {
        toast.error(e.message);
    } finally {
        confirmBusy.value = false;
    }
}
async function toggleLesson(lesson) {
    await teacher[lesson.is_published ? 'unpublishLesson' : 'publishLesson'](lesson.id);
    lesson.is_published = !lesson.is_published;
    toast.success(lesson.is_published ? t('courses.lessonPublished') : t('courses.lessonUnpublished'));
}
async function toggleVideo(video) {
    await teacher[video.is_published ? 'unpublishVideo' : 'publishVideo'](video.id);
    video.is_published = !video.is_published;
    toast.success(video.is_published ? t('courses.videoPublished') : t('courses.videoUnpublished'));
}

// ---- Reorder ----
async function moveUnit(unit, dir) {
    const list = units.value;
    const i = list.findIndex((u) => u.id === unit.id);
    const j = i + dir;
    if (j < 0 || j >= list.length) return;
    const ordered = list.map((u) => u.id);
    [ordered[i], ordered[j]] = [ordered[j], ordered[i]];
    try { await teacher.reorderUnits(courseId, ordered); toast.success(t('courses.unitsReordered')); }
    catch (e) { toast.error(e.message); }
    finally { refresh(); }
}
async function moveLesson(unit, lesson, dir) {
    const lessons = unit.lessons;
    const i = lessons.findIndex((l) => l.id === lesson.id);
    const j = i + dir;
    if (j < 0 || j >= lessons.length) return;
    const ordered = lessons.map((l) => l.id);
    [ordered[i], ordered[j]] = [ordered[j], ordered[i]];
    try { await teacher.reorderLessons(unit.id, ordered); toast.success(t('courses.lessonsReordered')); }
    catch (e) { toast.error(e.message); }
    finally { refresh(); }
}

// ---- Exam create ----
const examModal = ref(false);
const examForm = reactive({ title: '', description: '', duration_minutes: 30, pass_percentage: 50, max_attempts: 1, shuffle_questions: false, shuffle_options: false, show_result_immediately: true, scope: 'course', lesson_id: null, unit_ids: [] });
const examErrors = ref({});
const examBusy = ref(false);
const examScopeOptions = computed(() => [
    { value: 'course', label: t('courses.examScopeCourse') },
    { value: 'lesson', label: t('courses.examScopeLesson') },
    { value: 'units', label: t('courses.examScopeUnits') },
]);
const lessonOptions = computed(() => units.value.flatMap((u) => u.lessons.map((l) => ({ value: l.id, label: `${u.title} — ${l.title}` }))));
function openExam(scope = 'course', lesson = null) {
    Object.assign(examForm, { title: '', description: '', duration_minutes: 30, pass_percentage: 50, max_attempts: 1, shuffle_questions: false, shuffle_options: false, show_result_immediately: true, scope, lesson_id: lesson?.id || null, unit_ids: [] });
    examErrors.value = {};
    examModal.value = true;
}
function toggleExamUnit(id) {
    const key = Number(id);
    examForm.unit_ids = examForm.unit_ids.includes(key)
        ? examForm.unit_ids.filter((unitId) => unitId !== key)
        : [...examForm.unit_ids, key];
}
function examScopeLabel(exam) {
    if (exam.lesson_id) return `${t('courses.examForLesson')}: ${exam.lesson?.title || `#${exam.lesson_id}`}`;
    if (exam.unit_ids?.length) return `${t('courses.examForUnits')}: ${exam.unit_ids.length}`;
    return t('courses.examForCourse');
}
async function saveExam() {
    examBusy.value = true;
    examErrors.value = {};
    try {
        const payload = { ...examForm, lesson_id: null, unit_ids: null };
        if (examForm.scope === 'lesson') payload.lesson_id = examForm.lesson_id;
        if (examForm.scope === 'units') payload.unit_ids = examForm.unit_ids;
        delete payload.scope;
        await teacher.createExam(courseId, payload);
        toast.success(t('courses.examCreated'));
        examModal.value = false;
        loadExams(1);
    } catch (e) {
        examErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        examBusy.value = false;
    }
}

// ---- Enroll student ----
const enrollModal = ref(false);
const selectedStudent = ref('');
const enrollmentMode = ref('student');
const selectedAcademicYear = ref('');
const enrolledStudentIds = ref(new Set());
const academicYearOptions = computed(() => [
    { value: 'secondary_1', label: t('students.secondary1') },
    { value: 'secondary_2', label: t('students.secondary2') },
    { value: 'secondary_3', label: t('students.secondary3') },
]);
const enrollBusy = ref(false);
const enrollError = ref({});
async function openEnroll() {
    selectedStudent.value = '';
    selectedAcademicYear.value = '';
    enrollmentMode.value = 'student';
    enrollError.value = {};
    enrollModal.value = true;
    try {
        const enrolledRes = toList(await teacher.courseStudents(courseId, { per_page: 250 }));
        enrolledStudentIds.value = new Set(enrolledRes.items.map((e) => Number(e.student_id || e.student?.id)));
    } catch {
        enrolledStudentIds.value = new Set(students.value.map((s) => Number(s.student_id || s.student?.id)));
    }
}
async function doEnroll() {
    if (enrollmentMode.value === 'student' && !selectedStudent.value) return;
    if (enrollmentMode.value === 'year' && !selectedAcademicYear.value) return;
    enrollBusy.value = true;
    enrollError.value = {};
    try {
        const result = enrollmentMode.value === 'year'
            ? await teacher.enrollAcademicYear(courseId, selectedAcademicYear.value)
            : await teacher.enrollStudent(courseId, selectedStudent.value);
        toast.success(enrollmentMode.value === 'year'
            ? t('courses.academicYearEnrolled', { count: result?.enrolled_count || 0 })
            : t('students.enrolled'));
        enrollModal.value = false;
        loadStudents(1);
    } catch (e) {
        enrollError.value = fieldErrors(e);
        toast.error(e.isValidation ? t('common.fixFields') : e.message);
    } finally {
        enrollBusy.value = false;
    }
}

onMounted(async () => { await run(); await loadAssignments(); });
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link to="/teacher/courses" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.courses') }}</router-link>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900" dir="auto">{{ data?.title || $t('common.course') }}</h1>
                <AppBadge :tone="data?.status === 'published' ? 'success' : 'neutral'">{{ data?.status ? $t(`status.${data.status}`, data.status) : '' }}</AppBadge>
                <router-link :to="`/teacher/courses/${courseId}/edit`"><AppButton variant="outline" size="sm">{{ $t('common.edit') }}</AppButton></router-link>
            </div>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <Tabs :tabs="tabs" v-model="tab" />

            <div v-if="tab === 'content'" class="space-y-5">
                <div class="flex justify-end"><AppButton variant="outline" @click="openUnit()">{{ $t('courses.addUnitLabel') }}</AppButton></div>
                <EmptyState v-if="!units.length" icon="layers" :title="$t('courses.noUnitsTitle')" :message="$t('courses.buildUnits')">
                    <AppButton @click="openUnit()">{{ $t('courses.addUnitLabel') }}</AppButton>
                </EmptyState>
                <div v-else class="space-y-5">
                    <div v-for="unit in units" :key="unit.id" class="rounded-xl border border-ink-100 bg-white p-4 sm:p-5 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-md bg-ink-100 px-2.5 py-0.5 text-xs font-bold text-ink-700">
                                        {{ $t('common.unitN', { n: unit.position }) }}
                                    </span>
                                    <h3 class="text-base sm:text-lg font-bold text-ink-900" dir="auto">{{ unit.title }}</h3>
                                </div>
                                <p v-if="unit.description" class="mt-1 text-sm text-ink-500" dir="auto">{{ unit.description }}</p>
                            </div>
                            <div class="flex items-center gap-1.5 self-start sm:self-auto">
                                <button class="rounded-lg border border-ink-200 p-1.5 text-ink-500 hover:bg-ink-100 disabled:opacity-30" :disabled="unit.position <= 1" :aria-label="$t('common.previous')" @click="moveUnit(unit, -1)">↑</button>
                                <button class="rounded-lg border border-ink-200 p-1.5 text-ink-500 hover:bg-ink-100 disabled:opacity-30" :disabled="unit.position >= units.length" :aria-label="$t('common.next')" @click="moveUnit(unit, 1)">↓</button>
                                <button class="rounded-lg border border-ink-200 px-2.5 py-1.5 text-xs font-medium text-ink-700 hover:bg-ink-100" @click="openUnit(unit)">{{ $t('common.edit') }}</button>
                                <button class="rounded-lg border border-rose-200 px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="askDelete(unit, 'unit')">{{ $t('common.delete') }}</button>
                            </div>
                        </div>

                        <div class="mt-4 space-y-3">
                            <div v-for="lesson in unit.lessons" :key="lesson.id" class="rounded-xl border border-ink-100 bg-parchment-50/40 p-4 transition-all">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm sm:text-base font-semibold text-ink-900" dir="auto">{{ lesson.title }}</p>
                                            <AppBadge :tone="lesson.is_published ? 'success' : 'neutral'">{{ lesson.is_published ? $t('status.published') : $t('status.draft') }}</AppBadge>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between border-t border-ink-100/60 pt-3">
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <button class="inline-flex items-center rounded-lg border border-ink-200 bg-white px-2.5 py-1 font-medium text-ink-700 hover:bg-ink-50 shadow-xs" @click="openLesson(unit, lesson)">
                                            ✏️ {{ $t('common.edit') }}
                                        </button>
                                        <button
                                            class="inline-flex items-center rounded-lg border px-2.5 py-1 font-medium shadow-xs"
                                            :class="lesson.is_published ? 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100' : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'"
                                            @click="toggleLesson(lesson)"
                                        >
                                            {{ lesson.is_published ? $t('courses.unpublish') : $t('courses.publish') }}
                                        </button>
                                        <button class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 font-medium text-rose-700 hover:bg-rose-100 shadow-xs" @click="askDelete(lesson, 'lesson')">
                                            🗑️ {{ $t('common.delete') }}
                                        </button>
                                        <div class="flex items-center rounded-lg border border-ink-200 bg-white p-0.5">
                                            <button
                                                class="rounded px-1.5 py-0.5 text-ink-400 hover:bg-ink-100 disabled:opacity-30"
                                                :disabled="unit.lessons.findIndex((l) => l.id === lesson.id) === 0"
                                                @click="moveLesson(unit, lesson, -1)"
                                                title="تحريك لأعلى"
                                            >
                                                ↑
                                            </button>
                                            <button
                                                class="rounded px-1.5 py-0.5 text-ink-400 hover:bg-ink-100 disabled:opacity-30"
                                                :disabled="unit.lessons.findIndex((l) => l.id === lesson.id) === unit.lessons.length - 1"
                                                @click="moveLesson(unit, lesson, 1)"
                                                title="تحريك لأسفل"
                                            >
                                                ↓
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 w-full sm:w-auto">
                                        <AppButton variant="outline" size="sm" class="flex-1 sm:flex-initial text-center justify-center" @click="openExam('lesson', lesson)">
                                            📝 {{ $t('courses.createLessonExam') }}
                                        </AppButton>
                                        <AppButton variant="outline" size="sm" class="flex-1 sm:flex-initial text-center justify-center" @click="openVideo(lesson)">
                                            🎥 {{ $t('courses.addVideoLabel') }}
                                        </AppButton>
                                    </div>
                                </div>

                                <div v-if="lesson.videos?.length" class="mt-3 space-y-2">
                                    <div v-for="video in lesson.videos" :key="video.id" class="flex flex-col gap-2 rounded-lg bg-white p-2.5 sm:p-3 shadow-xs border border-ink-100 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                            <Icon name="play" :size="16" class="shrink-0 text-terracotta-500" />
                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink-800" dir="auto">{{ video.title }}</span>
                                            <span class="rounded bg-ink-50 px-1.5 py-0.5 text-xs text-ink-400 shrink-0">{{ video.provider }}</span>
                                            <AppBadge :tone="video.is_published ? 'success' : 'neutral'" class="shrink-0">{{ video.is_published ? $t('common.live') : $t('status.draft') }}</AppBadge>
                                        </div>
                                        <div class="flex items-center gap-2 self-end sm:self-auto border-t border-ink-50 pt-1.5 sm:border-0 sm:pt-0">
                                            <button class="rounded-md border border-ink-200 px-2 py-1 text-xs font-medium text-ink-600 hover:bg-ink-100" @click="openVideo(lesson, video)">{{ $t('common.edit') }}</button>
                                            <button class="rounded-md border border-amber-200 px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50" @click="toggleVideo(video)">{{ video.is_published ? $t('courses.unpub') : $t('courses.publish') }}</button>
                                            <button class="rounded-md border border-rose-200 px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="askDelete(video, 'video')">{{ $t('common.delete') }}</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <AppButton variant="outline" size="sm" @click="openLesson(unit)">{{ $t('courses.addLessonLabel') }}</AppButton>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assignments tab: the full staff loop — create, publish, review, grade -->
            <div v-else-if="tab === 'assignments'" class="space-y-4">
                <div class="flex justify-end"><AppButton @click="openAssignment()">{{ $t('assignments.create') }}</AppButton></div>
                <LoadingSpinner v-if="assignmentsBusy" />
                <EmptyState v-else-if="!assignments.length" icon="clipboard" :title="$t('assignments.emptyTeacherTitle')" :message="$t('assignments.emptyTeacherMessage')">
                    <AppButton @click="openAssignment()">{{ $t('assignments.create') }}</AppButton>
                </EmptyState>
                <div v-else class="space-y-3">
                    <div v-for="a in assignments" :key="a.id" class="rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-ink-800" dir="auto">{{ a.title }}</p>
                                <p class="mt-0.5 text-xs text-ink-400">
                                    {{ $t('assignments.due') }}: {{ a.due_at ? formatDate(a.due_at) : '—' }}
                                    · {{ $t('assignments.points') }}: {{ a.points }}
                                    <template v-if="a.counts">
                                        · {{ $t('assignments.counts', a.counts) }}
                                    </template>
                                </p>
                            </div>
                            <AppBadge :tone="a.is_published ? 'success' : 'warning'">
                                {{ a.is_published ? $t('status.published') : $t('status.draft') }}
                            </AppBadge>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <AppButton variant="outline" size="sm" @click="openSubmissions(a)">📥 {{ $t('assignments.submissions') }}</AppButton>
                                <AppButton variant="outline" size="sm" @click="togglePublish(a)">
                                    {{ a.is_published ? $t('assignments.unpublish') : $t('assignments.publish') }}
                                </AppButton>
                                <AppButton variant="outline" size="sm" @click="openAssignment(a)">{{ $t('common.edit') }}</AppButton>
                                <AppButton variant="outline" size="sm" class="border-rose-300 text-rose-700 hover:bg-rose-50" @click="askDeleteAssignment(a)">{{ $t('common.delete') }}</AppButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="tab === 'exams'" class="space-y-4">
                <div class="flex justify-end"><AppButton @click="openExam()">{{ $t('courses.createExam') }}</AppButton></div>
                <EmptyState v-if="!exams.length" icon="clipboard" :title="$t('courses.noExamsTitle')" :message="$t('courses.noExamsMessage')">
                    <AppButton @click="openExam()">{{ $t('courses.createExam') }}</AppButton>
                </EmptyState>
                <div v-else class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                    <div class="divide-y divide-ink-100">
                        <div v-for="e in exams" :key="e.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <Icon name="clipboard" :size="20" class="text-terracotta-500" />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800" dir="auto">{{ e.title }}</p>
                                <p class="text-xs text-ink-400">{{ examScopeLabel(e) }} · {{ $t('courses.examSummary', { questions: e.questions_count, attempts: e.attempts_count }) }}</p>
                            </div>
                            <AppBadge :tone="e.status === 'published' ? 'success' : 'neutral'">{{ $t(`status.${e.status}`, e.status) }}</AppBadge>
                            <router-link :to="`/teacher/exams/${e.id}`"><AppButton variant="outline" size="sm">{{ $t('common.manage') }}</AppButton></router-link>
                        </div>
                    </div>
                    <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="examsMeta" :meta="examsMeta" @change="loadExams" /></div>
                </div>
            </div>

            <div v-else class="space-y-4">
                <div class="flex justify-end"><AppButton @click="openEnroll">{{ $t('courses.enrollStudent') }}</AppButton></div>
                <EmptyState v-if="!students.length" icon="users" :title="$t('courses.noEnrollmentsTitle')" :message="$t('courses.noEnrollmentsMessage')">
                    <AppButton @click="openEnroll">{{ $t('courses.enrollStudent') }}</AppButton>
                </EmptyState>
                <div v-else class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                    <div class="divide-y divide-ink-100">
                        <div v-for="s in students" :key="s.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (s.student?.name || 'U').slice(0, 1) }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800" dir="auto">{{ s.student?.name }}</p>
                                <p class="text-xs text-ink-400">{{ s.student?.email }} · {{ $t('common.enrolledAt', { date: formatDate(s.enrolled_at) }) }}</p>
                            </div>
                            <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="askDelete(s, 'enrollment')">{{ $t('students.unenroll') }}</button>
                        </div>
                    </div>
                    <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="studentsMeta" :meta="studentsMeta" @change="loadStudents" /></div>
                </div>
            </div>
        </template>

        <!-- Unit modal -->
        <AppModal :open="unitModal" :title="unitForm.id ? $t('courses.editUnit') : $t('courses.addUnitLabel')" size="md" @close="unitModal = false">
            <form class="space-y-4" @submit.prevent="saveUnit">
                <AppInput v-model="unitForm.title" :label="$t('courses.unitTitle')" required id="unit-title" :error="unitErrors.title" />
                <AppTextarea v-model="unitForm.description" :label="$t('courses.description')" id="unit-desc" :error="unitErrors.description" :rows="2" />
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="unitModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="unitBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Lesson modal -->
        <AppModal :open="lessonModal" :title="lessonForm.id ? $t('courses.editLesson') : $t('courses.addLessonLabel')" size="lg" @close="lessonModal = false">
            <form class="space-y-4" @submit.prevent="saveLesson">
                <AppInput v-model="lessonForm.title" :label="$t('courses.lessonTitle')" required id="lesson-title" :error="lessonErrors.title" />
                <AppInput v-model="lessonForm.description" :label="$t('courses.summaryField')" id="lesson-desc" :error="lessonErrors.description" />
                <AppTextarea v-model="lessonForm.content" :label="$t('courses.content')" id="lesson-content" :error="lessonErrors.content" :rows="5" />
                <label class="flex items-center gap-2 text-sm text-ink-700"><input type="checkbox" v-model="lessonForm.is_published" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" /> {{ $t('status.published') }}</label>
                <!-- Attachments (edit mode only) -->
                <div v-if="lessonForm.id" class="rounded-lg border border-ink-100 p-3">
                    <p class="text-sm font-medium text-ink-800">{{ $t('courses.attachments') }}</p>
                    <ul v-if="lessonAttachments.length" class="mt-2 space-y-1">
                        <li v-for="att in lessonAttachments" :key="att.id" class="flex items-center gap-2 text-sm">
                            <span class="min-w-0 flex-1 truncate text-ink-700">📎 {{ att.title }}</span>
                            <button type="button" class="text-rose-600 hover:underline" :disabled="attachmentBusy" @click="deleteAttachment(att.id)">{{ $t('common.delete') }}</button>
                        </li>
                    </ul>
                    <input type="file" class="mt-2 text-sm" :disabled="attachmentBusy" @change="onAttachmentFile" />
                </div>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="lessonModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="lessonBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Video modal -->
        <AppModal :open="videoModal" :title="videoForm.id ? $t('courses.editVideo') : $t('courses.addVideoLabel')" size="lg" @close="videoModal = false">
            <form class="space-y-4" @submit.prevent="saveVideo">
                <AppInput v-model="videoForm.title" :label="$t('courses.titleField')" required id="video-title" :error="videoErrors.title" />
                <AppSelect v-model="videoForm.provider" :label="$t('courses.provider')" :options="providerOptions" id="video-provider" :error="videoErrors.provider" />
                <template v-if="videoForm.provider === 'youtube'">
                    <AppInput v-model="videoForm.provider_video_id" :label="$t('courses.youtubeId')" id="video-vid" :error="videoErrors.provider_video_id" placeholder="dQw4w9WgXcQ" />
                </template>
                <template v-else>
                    <AppInput v-model="videoForm.storage_path" :label="$t('courses.storagePath')" id="video-storage" :error="videoErrors.storage_path" />
                </template>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="videoForm.duration" :label="$t('courses.durationSeconds')" type="number" id="video-duration" :error="videoErrors.duration" />
                    <label class="flex items-center gap-2 text-sm text-ink-700"><input type="checkbox" v-model="videoForm.is_published" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" /> {{ $t('status.published') }}</label>
                </div>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="videoModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="videoBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Exam modal -->
        <AppModal :open="examModal" :title="$t('courses.createExam')" size="lg" @close="examModal = false">
            <form class="space-y-4" @submit.prevent="saveExam">
                <AppInput v-model="examForm.title" :label="$t('exams.titleField')" required id="exam-title" :error="examErrors.title" />
                <AppSelect v-model="examForm.scope" :label="$t('courses.examScope')" :options="examScopeOptions" id="exam-scope" />
                <AppSelect v-if="examForm.scope === 'lesson'" v-model="examForm.lesson_id" :label="$t('courses.lessonTitle')" :options="lessonOptions" id="exam-lesson" :placeholder="$t('common.select')" :error="examErrors.lesson_id" />
                <div v-if="examForm.scope === 'units'" class="space-y-2"><p class="text-sm font-medium text-ink-800">{{ $t('courses.selectUnits') }}</p><label v-for="unit in units" :key="unit.id" class="flex items-center gap-2 rounded-lg border border-ink-200 px-3 py-2 text-sm text-ink-700"><input type="checkbox" :checked="examForm.unit_ids.includes(unit.id)" @change="toggleExamUnit(unit.id)" />{{ unit.title }}</label><p v-if="examErrors.unit_ids" class="text-xs font-medium text-rose-600">{{ examErrors.unit_ids }}</p></div>
                <AppTextarea v-model="examForm.description" :label="$t('exams.description')" id="exam-desc" :error="examErrors.description" :rows="2" />
                <div class="grid gap-4 sm:grid-cols-3">
                    <AppInput v-model="examForm.duration_minutes" :label="$t('exams.durationMin')" type="number" id="exam-duration" :error="examErrors.duration_minutes" />
                    <AppInput v-model="examForm.pass_percentage" :label="$t('exams.passPercent')" type="number" id="exam-pass" :error="examErrors.pass_percentage" />
                    <AppInput v-model="examForm.max_attempts" :label="$t('exams.maxAttempts')" type="number" id="exam-max" :error="examErrors.max_attempts" />
                </div>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="examModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="examBusy">{{ $t('common.create') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Enroll student modal -->
        <!-- Assignment create/edit -->
        <AppModal :open="assignmentModal" :title="assignmentForm.id ? $t('assignments.edit') : $t('assignments.create')" size="lg" @close="assignmentModal = false">
            <form class="space-y-4" @submit.prevent="saveAssignment">
                <AppInput v-model="assignmentForm.title" :label="$t('assignments.titleLabel')" required id="assignment-title" :error="assignmentErrors.title" />
                <AppTextarea v-model="assignmentForm.description" :label="$t('assignments.descriptionLabel')" :rows="4" id="assignment-desc" :error="assignmentErrors.description" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="assignmentForm.due_at" type="datetime-local" :label="$t('assignments.dueAt')" id="assignment-due" :error="assignmentErrors.due_at" />
                    <AppInput v-model.number="assignmentForm.points" type="number" min="1" :label="$t('assignments.pointsLabel')" required id="assignment-points" :error="assignmentErrors.points" />
                </div>
                <label class="flex items-center gap-2 text-sm text-ink-700">
                    <input v-model="assignmentForm.is_published" type="checkbox" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" />
                    {{ $t('status.published') }}
                </label>
                <div class="flex justify-end gap-2">
                    <AppButton variant="outline" @click="assignmentModal = false">{{ $t('common.cancel') }}</AppButton>
                    <AppButton type="submit" :loading="assignmentBusy">{{ $t('common.save') }}</AppButton>
                </div>
            </form>
        </AppModal>

        <!-- Submissions review -->
        <AppModal :open="submissionsModal" :title="$t('assignments.submissionsTitle')" size="xl" @close="submissionsModal = false">
            <LoadingSpinner v-if="submissionsBusy" />
            <EmptyState v-else-if="!submissions.length" icon="clipboard" :title="$t('assignments.noSubmissionsTitle')" :message="$t('assignments.noSubmissionsMessage')" />
            <div v-else class="overflow-auto rounded-lg border border-ink-100">
                <table class="w-full text-sm">
                    <thead class="bg-ink-50 text-ink-500">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ $t('students.name') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('assignments.submittedAt') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('assignments.submissionStatus') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('assignments.grade') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        <tr v-for="sub in submissions" :key="sub.id">
                            <td class="px-3 py-2" dir="auto">{{ sub.student?.name }}</td>
                            <td class="px-3 py-2">{{ sub.submitted_at ? formatDate(sub.submitted_at) : '—' }}
                                <AppBadge v-if="sub.is_late" tone="danger">{{ $t('assignments.lateBadge') }}</AppBadge>
                            </td>
                            <td class="px-3 py-2">{{ sub.status }}</td>
                            <td class="px-3 py-2">{{ sub.score ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <AppButton v-if="sub.file" variant="ghost" size="sm" @click="downloadSubmissionFile(sub)">📎</AppButton>
                                    <AppButton variant="outline" size="sm" @click="openGrade(sub)">📝 {{ $t('assignments.gradeAction') }}</AppButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppModal>

        <!-- Grade submission -->
        <AppModal :open="gradeModal" :title="$t('assignments.gradeTitle')" size="md" @close="gradeModal = false">
            <form class="space-y-4" @submit.prevent="saveGrade">
                <p class="text-sm text-ink-500">
                    {{ $t('assignments.gradeOutOf', { max: submissionsFor?.points ?? gradeTarget?.assignment?.points ?? 0 }) }}
                </p>
                <AppInput v-model.number="gradeForm.score" type="number" min="0" step="0.5" :label="$t('assignments.scoreLabel')" required id="grade-score" />
                <AppTextarea v-model="gradeForm.feedback" :label="$t('assignments.feedbackLabel')" :rows="4" id="grade-feedback" />
                <div class="flex justify-end gap-2">
                    <AppButton variant="outline" @click="gradeModal = false">{{ $t('common.cancel') }}</AppButton>
                    <AppButton type="submit" :loading="gradeBusy">{{ $t('assignments.publishGrade') }}</AppButton>
                </div>
            </form>
        </AppModal>

        <ConfirmDialog
            :open="confirmDeleteAssignment"
            :title="$t('assignments.deleteTitle')"
            :message="$t('assignments.deleteMessage')"
            confirm-text="حذف"
            tone="danger"
            @close="confirmDeleteAssignment = false"
            @confirm="deleteAssignment"
        />

        <AppModal :open="enrollModal" :title="$t('courses.enrollStudent')" size="md" @close="enrollModal = false">
            <form class="space-y-4" @submit.prevent="doEnroll">
                <AppSelect
                    v-model="enrollmentMode"
                    :label="$t('courses.enrollmentMode')"
                    :options="[
                        { value: 'student', label: $t('courses.oneStudent') },
                        { value: 'year', label: $t('courses.academicYear') }
                    ]"
                    id="course-enroll-mode"
                />

                <div v-if="enrollmentMode === 'student'" class="space-y-1">
                    <StudentSearchSelect
                        v-model="selectedStudent"
                        :label="$t('common.student')"
                        :placeholder="$t('courses.searchStudentPlaceholder')"
                        :error="enrollError.student_id"
                        :exclude-ids="enrolledStudentIds"
                        id="course-enroll-student"
                    />
                </div>

                <div v-else class="space-y-2">
                    <AppSelect
                        v-model="selectedAcademicYear"
                        :label="$t('courses.academicYear')"
                        :options="academicYearOptions"
                        id="course-enroll-year"
                        :placeholder="$t('common.select')"
                        :error="enrollError.academic_year"
                    />
                    <p class="text-xs text-ink-500">
                        {{ $t('courses.enrollYearHint') }}
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="enrollBusy" @click="enrollModal = false">{{ $t('common.cancel') }}</AppButton>
                    <AppButton type="submit" :loading="enrollBusy" :disabled="enrollmentMode === 'student' ? !selectedStudent : !selectedAcademicYear">{{ $t('students.enroll') }}</AppButton>
                </div>
            </form>
        </AppModal>

        <ConfirmDialog
            :open="Boolean(confirmTarget)"
            :title="confirmTitle"
            :message="confirmMessage"
            :confirm-text="$t('common.confirm')"
            :loading="confirmBusy"
            @close="confirmTarget = null"
            @confirm="runDelete"
        />
    </div>
</template>
