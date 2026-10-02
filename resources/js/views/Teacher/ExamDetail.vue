<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { teacher, toList } from '@/api';
import { toggleBoundedSelection } from '@/utils/boundedSelection';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppModal from '@/components/ui/AppModal.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Icon from '@/components/ui/Icon.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const examId = route.params.id;
const toast = useToast();
const { fieldErrors, extractFieldError } = useFieldErrors();

const tab = ref('questions');
const { loading, error, data, run } = useAsync(async () => {
    const e = await teacher.exam(examId);
    await loadAttempts();
    await loadIntegrity();
    return e;
});

const attempts = ref([]);
const attemptsMeta = ref(null);
const integrity = ref(null);

// Grading Modal State
const gradingAttempt = ref(null);
const gradingData = ref(null);
const gradingLoading = ref(false);
const gradeForm = reactive({});
const gradeFeedback = reactive({});
const gradingBusy = ref(false);
const publishBusy = ref(false);

// ---- Attempt management: grouped by student (P1) and search ----
const attemptsView = ref('flat'); // 'flat' | 'student'
const grouped = ref([]);
const groupedSummary = ref(null);
const groupedMeta = ref(null);
const studentSearch = ref('');
const statusFilter = ref('');
const exactScoreFilter = ref('');
const integrityFilter = ref('');
const selectedAttemptIds = ref([]);
const operationSelectionLimit = 100;
const deleteSelectedOpen = ref(false);
const deleteAttemptsBusy = ref(false);
const makeUpModal = ref(false);
const makeUpLoadBusy = ref(false);
const makeUpAssignBusy = ref(false);
const makeUpStudents = ref([]);
const makeUpStudentsMeta = ref(null);
const makeUpAssignments = ref([]);
const makeUpStudentSearch = ref('');
const makeUpStudentPage = ref(1);
const selectedMakeUpStudentIds = ref([]);
const makeUpReason = ref('');
const revokeMakeUpTarget = ref(null);
const revokeMakeUpBusy = ref(false);
let makeUpSearchTimer = null;
const groupBusy = ref(false);
const attemptsBusy = ref(false);
const expandedStudent = ref(null);
let searchTimer = null;

const attemptStatusOptions = computed(() => [
    { value: '', label: t('exams.allStatuses') },
    { value: 'in_progress', label: t('exams.statusInProgressLabel') },
    { value: 'submitted', label: t('exams.statusSubmittedLabel') },
    { value: 'grading', label: t('exams.statusGradingLabel') },
    { value: 'published', label: t('exams.statusPublishedLabel') },
    { value: 'expired', label: t('exams.statusExpiredLabel') },
]);

const integrityStatusOptions = computed(() => [
    { value: '', label: t('exams.allIntegrityStatuses') },
    { value: 'normal', label: t('exams.integrityNormal') },
    { value: 'monitoring', label: t('exams.integrityMonitoring') },
    { value: 'flagged', label: t('exams.integrityFlagged') },
    { value: 'reviewed', label: t('exams.integrityReviewed') },
    { value: 'cleared', label: t('exams.integrityCleared') },
]);

function attemptFilterParams() {
    const params = { per_page: 15 };
    if (studentSearch.value.trim()) params.search = studentSearch.value.trim();
    if (statusFilter.value) params.status = statusFilter.value;
    if (integrityFilter.value) params.integrity_status = integrityFilter.value;
    // An explicit string check is critical: numeric score zero is a real filter.
    if (exactScoreFilter.value !== '' && exactScoreFilter.value !== null) {
        params.score = Number(exactScoreFilter.value);
    }
    return params;
}

async function loadGrouped(page = 1) {
    groupBusy.value = true;
    try {
        const res = await teacher.attemptsGrouped(examId, { ...attemptFilterParams(), page });
        grouped.value = res.students || [];
        groupedSummary.value = res.summary || null;
        groupedMeta.value = res.pagination || null;
    } catch (e) {
        toast.error(e.message);
    } finally {
        groupBusy.value = false;
    }
}

function onStudentSearch() {
    onAttemptFilterChange();
}

function onAttemptFilterChange() {
    selectedAttemptIds.value = [];
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if (attemptsView.value === 'student') {
            loadGrouped(1);
        } else {
            loadAttempts(1);
        }
    }, 300);
}

function clearStudentSearch() {
    studentSearch.value = '';
    if (attemptsView.value === 'student') {
        loadGrouped(1);
    } else {
        loadAttempts(1);
    }
}

const activeMakeUpStudentIds = computed(() => new Set(
    makeUpAssignments.value
        .filter((assignment) => assignment.status === 'assigned')
        .map((assignment) => Number(assignment.student_id))
));

async function loadMakeUpAssignments() {
    const response = toList(await teacher.makeUpAssignments(examId, { per_page: 100 }));
    makeUpAssignments.value = response.items;
}

async function loadMakeUpStudents(page = 1) {
    makeUpStudentPage.value = page;
    const response = toList(await teacher.courseStudents(data.value.course_id, {
        per_page: 15,
        page,
        ...(makeUpStudentSearch.value.trim() ? { search: makeUpStudentSearch.value.trim() } : {}),
    }));
    makeUpStudents.value = response.items;
    makeUpStudentsMeta.value = response.meta;
}

async function openMakeUpModal() {
    makeUpModal.value = true;
    makeUpLoadBusy.value = true;
    selectedMakeUpStudentIds.value = [];
    makeUpReason.value = '';
    makeUpStudentSearch.value = '';
    try {
        await Promise.all([loadMakeUpStudents(1), loadMakeUpAssignments()]);
    } catch (e) {
        toast.error(e.message);
    } finally {
        makeUpLoadBusy.value = false;
    }
}

function onMakeUpStudentSearch() {
    clearTimeout(makeUpSearchTimer);
    makeUpSearchTimer = setTimeout(() => loadMakeUpStudents(1), 300);
}

function toggleMakeUpStudent(studentId) {
    const result = toggleBoundedSelection(
        selectedMakeUpStudentIds.value,
        studentId,
        operationSelectionLimit,
        activeMakeUpStudentIds.value.has(Number(studentId)),
    );

    if (result.action === 'limit') {
        toast.info(t('exams.selectionLimit', { n: operationSelectionLimit }));
        return;
    }

    selectedMakeUpStudentIds.value = result.ids;
}

async function assignMakeUps() {
    const studentIds = selectedMakeUpStudentIds.value.filter((id) => !activeMakeUpStudentIds.value.has(Number(id)));
    if (!studentIds.length) return;

    makeUpAssignBusy.value = true;
    try {
        await teacher.assignExamMakeUps(examId, studentIds, makeUpReason.value.trim() || null);
        toast.success(t('exams.makeUpsAssignedToast', { n: studentIds.length }));
        selectedMakeUpStudentIds.value = [];
        await loadMakeUpAssignments();
    } catch (e) {
        toast.error(e.message);
    } finally {
        makeUpAssignBusy.value = false;
    }
}

function requestRevokeMakeUp(assignment) {
    revokeMakeUpTarget.value = assignment;
}

async function revokeMakeUp() {
    if (!revokeMakeUpTarget.value) return;
    revokeMakeUpBusy.value = true;
    try {
        await teacher.revokeExamMakeUp(examId, revokeMakeUpTarget.value.id);
        toast.success(t('exams.makeUpRevokedToast'));
        revokeMakeUpTarget.value = null;
        await loadMakeUpAssignments();
    } catch (e) {
        toast.error(e.message);
    } finally {
        revokeMakeUpBusy.value = false;
    }
}

function makeUpStatusLabel(status) {
    return t(`exams.makeUpStatus.${status}`, status);
}

function studentExplanation(question) {
    const answer = gradingData.value?.answers?.find((item) => Number(item.question_id) === Number(question.id));
    return question?.explanation ?? answer?.explanation ?? '';
}

function switchAttemptsView(mode) {
    selectedAttemptIds.value = [];
    attemptsView.value = mode;
    if (mode === 'student') {
        loadGrouped();
    } else {
        loadAttempts(1);
    }
}

function toggleStudent(studentId) {
    expandedStudent.value = expandedStudent.value === studentId ? null : studentId;
}

const exportBusy = ref(false);
async function exportResults(format) {
    exportBusy.value = true;
    try {
        await teacher.exportResults(examId, format);
        toast.success(t('exams.exportStarted'));
    } catch (e) {
        toast.error(e.message);
    } finally {
        exportBusy.value = false;
    }
}

async function loadAttempts(p = 1) {
    attemptsBusy.value = true;
    try {
        const params = { ...attemptFilterParams(), page: p };
        const res = toList(await teacher.examAttempts(examId, params));
        attempts.value = res.items;
        attemptsMeta.value = res.meta;
    } catch (e) {
        toast.error(e.message);
    } finally {
        attemptsBusy.value = false;
    }
}
function toggleAttemptSelection(attempt) {
    const result = toggleBoundedSelection(
        selectedAttemptIds.value,
        attempt.id,
        operationSelectionLimit,
        attempt.status === 'in_progress',
    );

    if (result.action === 'limit') {
        toast.info(t('exams.selectionLimit', { n: operationSelectionLimit }));
        return;
    }

    selectedAttemptIds.value = result.ids;
}

async function deleteSelectedAttempts() {
    if (!selectedAttemptIds.value.length) return;
    deleteAttemptsBusy.value = true;
    try {
        await teacher.bulkDeleteExamAttempts(examId, [...selectedAttemptIds.value]);
        toast.success(t('exams.deletedAttemptsToast', { n: selectedAttemptIds.value.length }));
        selectedAttemptIds.value = [];
        deleteSelectedOpen.value = false;
        await loadAttempts(1);
        if (attemptsView.value === 'student') await loadGrouped(1);
    } catch (e) {
        toast.error(e.message);
    } finally {
        deleteAttemptsBusy.value = false;
    }
}

async function loadIntegrity() {
    try { integrity.value = await teacher.integritySettings(examId); }
    catch { integrity.value = null; }
}

const questions = computed(() => {
    const qs = data.value?.questions;
    if (!qs) return [];
    const arr = Array.isArray(qs) ? qs : (qs.data || []);
    return arr.map((q) => ({ ...q, options: Array.isArray(q.options) ? q.options : (q.options?.data || []) }));
});

// Structure at a glance: how many of each type, and the paper's total marks.
const mcqCount = computed(() => questions.value.filter((q) => q.type !== 'essay').length);
const essayCount = computed(() => questions.value.filter((q) => q.type === 'essay').length);
const totalMarks = computed(() => questions.value.reduce((sum, q) => sum + (Number(q.points) || 0), 0));

const tabs = computed(() => [
    { key: 'questions', label: t('exams.questionsTab') || 'الأسئلة' },
    { key: 'attempts', label: t('exams.attemptsTab') || 'محاولات الطلاب والتصحيح' },
]);

const questionTypeOptions = computed(() => [
    { value: 'single_choice', label: t('exams.qTypeSingle') },
    { value: 'multiple_choice', label: t('exams.qTypeMultiple') },
    { value: 'essay', label: t('exams.qTypeEssay') },
]);

const integrityFields = computed(() => [
    { k: 'fullscreen_required', label: t('exams.fullscreenRequired') },
    { k: 'prevent_copy', label: t('exams.blockCopy') },
    { k: 'prevent_paste', label: t('exams.blockPaste') },
    { k: 'prevent_context_menu', label: t('exams.blockContext') },
    { k: 'detect_tab_switch', label: t('exams.detectTab') },
    { k: 'detect_window_blur', label: t('exams.detectBlur') },
    { k: 'detect_keyboard_shortcuts', label: t('exams.detectShortcuts') },
    { k: 'terminate_on_violation', label: t('exams.terminateOnViolation') },
]);

// ---- Publish/archive/delete ----
const statusBusy = ref(false);
async function setStatus(kind) {
    statusBusy.value = true;
    try {
        await (kind === 'publish' ? teacher.publishExam : teacher.archiveExam)(examId);
        toast.success(kind === 'publish' ? (t('exams.publishedToast') || 'تم نشر الامتحان') : (t('exams.archived') || 'تم الأرشيف'));
        await run();
    } catch (e) {
        toast.error(e.message);
    } finally {
        statusBusy.value = false;
    }
}
const deleteOpen = ref(false);
const deleteBusy = ref(false);
async function remove() {
    deleteBusy.value = true;
    try {
        await teacher.deleteExam(examId);
        toast.success(t('exams.deleted'));
        router.push(`/teacher/courses/${data.value?.course_id || ''}`);
    } catch (e) {
        toast.error(e.message);
    } finally {
        deleteBusy.value = false;
    }
}

// ---- Question modal ----
const qModal = ref(false);
const qForm = reactive({ id: null, question_text: '', type: 'single_choice', points: 1, reference_answer: '', explanation_enabled: false, explanation_required: false, image_url: null });
const qErrors = ref({});
const qBusy = ref(false);
const qImageBusy = ref(false);
function openQuestion(q = null) {
    qForm.id = q?.id || null;
    qForm.question_text = q?.question_text || '';
    qForm.type = q?.type || 'single_choice';
    qForm.points = q?.points || 1;
    qForm.reference_answer = q?.reference_answer || '';
    qForm.explanation_enabled = Boolean(q?.explanation_enabled);
    qForm.explanation_required = Boolean(q?.explanation_required);
    qForm.image_url = q?.image_url || null;
    qErrors.value = {};
    qModal.value = true;
}
function onExplanationEnabledChange() {
    if (!qForm.explanation_enabled) qForm.explanation_required = false;
}

async function saveQuestion() {
    qBusy.value = true;
    qErrors.value = {};
    try {
        const payload = {
            question_text: qForm.question_text,
            type: qForm.type,
            points: Number(qForm.points),
            reference_answer: qForm.reference_answer || null,
            explanation_enabled: qForm.type !== 'essay' && qForm.explanation_enabled,
            explanation_required: qForm.type !== 'essay' && qForm.explanation_enabled && qForm.explanation_required,
        };
        if (qForm.id) await teacher.updateQuestion(qForm.id, payload);
        else await teacher.createQuestion(examId, payload);
        toast.success(qForm.id ? t('exams.questionUpdated') : t('exams.questionCreated'));
        qModal.value = false;
        refresh();
    } catch (e) {
        qErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        qBusy.value = false;
    }
}

async function uploadQuestionImage(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !qForm.id) return;

    qImageBusy.value = true;
    try {
        const updated = await teacher.uploadQuestionImage(qForm.id, file);
        qForm.image_url = updated.image_url;
        toast.success(t('examQuestions.imageUploaded'));
        refresh();
    } catch (e) {
        toast.error(e.message);
    } finally {
        qImageBusy.value = false;
    }
}

async function removeQuestionImage() {
    if (!qForm.id) return;

    qImageBusy.value = true;
    try {
        await teacher.removeQuestionImage(qForm.id);
        qForm.image_url = null;
        toast.success(t('examQuestions.imageRemoved'));
        refresh();
    } catch (e) {
        toast.error(e.message);
    } finally {
        qImageBusy.value = false;
    }
}

// ---- Option modal ----
const oModal = ref(false);
const oTarget = ref(null);
const oForm = reactive({ id: null, option_text: '', is_correct: false });
const oErrors = ref({});
const oBusy = ref(false);
const regradeTarget = ref(null);
const regradeBusy = ref(false);
function openOption(q, option = null) {
    oTarget.value = q;
    oForm.id = option?.id || null;
    oForm.option_text = option?.option_text || '';
    oForm.is_correct = option ? Boolean(option.is_correct) : false;
    oErrors.value = {};
    oModal.value = true;
}
async function saveOption() {
    oBusy.value = true;
    oErrors.value = {};
    try {
        if (oForm.id) await teacher.updateOption(oForm.id, { option_text: oForm.option_text, is_correct: oForm.is_correct });
        else await teacher.createOption(oTarget.value.id, { option_text: oForm.option_text, is_correct: oForm.is_correct });
        toast.success(oForm.id ? t('exams.optionUpdated') : t('exams.optionAdded'));
        oModal.value = false;
        refresh();
    } catch (e) {
        oErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        oBusy.value = false;
    }
}
async function toggleCorrect(q, option) {
    try {
        await teacher.updateOption(option.id, { option_text: option.option_text, is_correct: !option.is_correct });
        option.is_correct = !option.is_correct;
        toast.success(option.is_correct ? t('exams.markedCorrect') : t('exams.markedIncorrect'));
    } catch (e) {
        toast.error(e.message);
    }
}
function requestQuestionRegrade(question) {
    regradeTarget.value = question;
}

async function regradeQuestionAttempts() {
    const question = regradeTarget.value;
    if (!question) return;

    regradeBusy.value = true;
    try {
        const result = await teacher.regradeQuestionAttempts(question.id, { confirmed: true });
        const regraded = Number(result?.attempts_regraded) || 0;
        const scoresChanged = Number(result?.scores_changed) || 0;
        toast.success(regraded === 0
            ? t('exams.regradeNoAttempts')
            : t('exams.regradeSuccess', { attempts: regraded, scores: scoresChanged }));
        regradeTarget.value = null;
        await refresh();
    } catch (e) {
        toast.error(extractFieldError(e) || e.message);
    } finally {
        regradeBusy.value = false;
    }
}

async function deleteOption(q, option) {
    try {
        await teacher.deleteOption(option.id);
        const i = q.options.findIndex((o) => o.id === option.id);
        if (i !== -1) q.options.splice(i, 1);
        toast.success(t('exams.optionDeleted'));
    } catch (e) {
        toast.error(e.message);
    }
}

// ---- ESSAY GRADING MODAL ----
async function openGrading(attempt) {
    gradingAttempt.value = attempt;
    gradingLoading.value = true;
    try {
        const res = await teacher.attempt(attempt.id);
        gradingData.value = res.data || res;
        // Populate form
        if (gradingData.value?.questions?.length) {
            gradingData.value.questions.forEach((q) => {
                gradeForm[q.id] = q.points_earned !== null ? q.points_earned : 0;
                gradeFeedback[q.id] = q.feedback || '';
            });
        } else if (gradingData.value?.answers) {
            gradingData.value.answers.forEach((ans) => {
                gradeForm[ans.question_id] = ans.points_earned !== null ? ans.points_earned : 0;
                gradeFeedback[ans.question_id] = ans.feedback || '';
            });
        }
    } catch (e) {
        toast.error(e.message);
        gradingAttempt.value = null;
    } finally {
        gradingLoading.value = false;
    }
}

async function submitEssayGrade(questionId) {
    if (!gradingAttempt.value) return;
    gradingBusy.value = true;
    try {
        const pts = Number(gradeForm[questionId] || 0);
        const fb = gradeFeedback[questionId] || null;
        const res = await teacher.gradeEssay(gradingAttempt.value.id, {
            question_id: questionId,
            awarded_points: pts,
            feedback: fb,
        });
        const updated = res.data || res;
        gradingData.value = updated;
        toast.success(t('exams.essayGradeSaved'));
        loadAttempts(attemptsMeta.value?.current_page || 1);
        if (attemptsView.value === 'student') loadGrouped();
    } catch (e) {
        toast.error(e.message);
    } finally {
        gradingBusy.value = false;
    }
}

async function submitPublishGrades() {
    if (!gradingAttempt.value) return;
    publishBusy.value = true;
    try {
        const res = await teacher.publishGrades(gradingAttempt.value.id);
        const updated = res.data || res;
        gradingData.value = updated;
        toast.success(t('exams.gradesPublishedToast'));
        loadAttempts(attemptsMeta.value?.current_page || 1);
        if (attemptsView.value === 'student') loadGrouped();
    } catch (e) {
        toast.error(e.message);
    } finally {
        publishBusy.value = false;
    }
}

// ---- Delete helpers ----
const confirmTarget = ref(null);
const confirmBusy = ref(false);
async function runDelete(kind) {
    confirmBusy.value = true;
    try {
        if (kind === 'question') await teacher.deleteQuestion(confirmTarget.value.id);
        await refresh();
        toast.success(t('common.deleted'));
        confirmTarget.value = null;
    } catch (e) {
        toast.error(e.message);
    } finally {
        confirmBusy.value = false;
    }
}

// ---- Integrity settings ----
const intModal = ref(false);
const intForm = reactive({ fullscreen_required: true, prevent_copy: true, prevent_paste: true, prevent_context_menu: true, detect_tab_switch: true, detect_window_blur: true, detect_keyboard_shortcuts: true, terminate_on_violation: true, violation_warning_threshold: 5 });
const intBusy = ref(false);
function openIntegrity() {
    const settings = integrity.value?.settings;
    if (settings) {
        intForm.fullscreen_required = Boolean(settings.fullscreen_required);
        intForm.prevent_copy = Boolean(settings.prevent_copy);
        intForm.prevent_paste = Boolean(settings.prevent_paste);
        intForm.prevent_context_menu = Boolean(settings.prevent_context_menu);
        intForm.detect_tab_switch = Boolean(settings.detect_tab_switch);
        intForm.detect_window_blur = Boolean(settings.detect_window_blur);
        intForm.detect_keyboard_shortcuts = Boolean(settings.detect_keyboard_shortcuts);
        intForm.terminate_on_violation = Boolean(settings.terminate_on_violation);
        // NULL = use the platform default (config/integrity.php).
        intForm.violation_warning_threshold = settings.violation_warning_threshold ?? 5;
    }
    intModal.value = true;
}
async function saveIntegrity() {
    intBusy.value = true;
    try {
        const threshold = Number(intForm.violation_warning_threshold);
        await teacher.updateIntegritySettings(examId, {
            ...intForm,
            violation_warning_threshold: Number.isFinite(threshold) ? Math.min(20, Math.max(1, Math.round(threshold))) : null,
        });
        toast.success(t('exams.settingsUpdated'));
        intModal.value = false;
        loadIntegrity();
    } catch (e) {
        toast.error(e.message);
    } finally {
        intBusy.value = false;
    }
}

async function refresh() { await run(); loadAttempts(1); loadIntegrity(); }
onMounted(() => run());

function attemptTone(statusOrAttempt) {
    const status = typeof statusOrAttempt === 'object' && statusOrAttempt !== null ? statusOrAttempt.status : statusOrAttempt;
    const published = typeof statusOrAttempt === 'object' && statusOrAttempt !== null && Boolean(statusOrAttempt.grades_published_at || statusOrAttempt.grades_published);
    if (status === 'published' || (status === 'submitted' && published)) return 'success';
    return { submitted: 'info', grading: 'warning', in_progress: 'info', expired: 'danger' }[status] || 'neutral';
}

function attemptStatusLabel(statusOrAttempt) {
    const status = typeof statusOrAttempt === 'object' && statusOrAttempt !== null ? statusOrAttempt.status : statusOrAttempt;
    const published = typeof statusOrAttempt === 'object' && statusOrAttempt !== null && Boolean(statusOrAttempt.grades_published_at || statusOrAttempt.grades_published);
    if (status === 'published' || (status === 'submitted' && published)) return t('exams.statusPublishedLabel');
    if (status === 'grading') return t('exams.statusGradingLabel');
    if (status === 'submitted') return t('exams.statusSubmittedLabel');
    if (status === 'in_progress') return t('exams.statusInProgressLabel');
    if (status === 'expired') return t('exams.statusExpiredLabel');
    return status || '—';
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link :to="`/teacher/courses/${data?.course_id || ''}`" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('common.course') }}</router-link>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900" dir="auto">{{ data?.title || $t('common.exam') }}</h1>
                <AppBadge :tone="data?.status === 'published' ? 'success' : 'neutral'">{{ data?.status ? $t(`status.${data.status}`, data.status) : '' }}</AppBadge>
                <router-link :to="`/teacher/exams/${examId}/edit`"><AppButton variant="outline" size="sm">{{ $t('exams.editExam') }}</AppButton></router-link>
            </div>
            <p class="mt-1 text-ink-500" dir="auto">{{ data?.description }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <AppButton v-if="data?.status !== 'published'" variant="success" size="sm" :loading="statusBusy" @click="setStatus('publish')">{{ $t('exams.publish') }}</AppButton>
            <AppButton v-else variant="outline" size="sm" :loading="statusBusy" @click="setStatus('archive')">{{ $t('exams.archive') }}</AppButton>
            <AppButton variant="danger" size="sm" @click="deleteOpen = true">{{ $t('common.delete') }}</AppButton>
            <AppButton variant="outline" size="sm" @click="openIntegrity">{{ $t('exams.integritySettings') }}</AppButton>
            <AppButton v-if="data?.status === 'published'" variant="outline" size="sm" @click="openMakeUpModal">{{ $t('exams.assignMakeUp') }}</AppButton>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <Tabs :tabs="tabs" v-model="tab" />

            <div v-if="tab === 'questions'" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
                    <p class="text-sm text-ink-600">
                        <span class="font-semibold text-ink-800">{{ $t('exams.structureSummary') }}</span>
                        <span class="ms-2">{{ $t('exams.structureCounts', { total: questions.length, mcq: mcqCount, essay: essayCount, marks: totalMarks }) }}</span>
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton variant="outline" size="sm" @click="openQuestion()">{{ $t('exams.addQuestion') }}</AppButton>
                        <AppButton size="sm" @click="router.push(`/teacher/exams/${examId}/questions`)">
                            <Icon name="layers" :size="15" />
                            {{ $t('examQuestions.bulkEditor') }}
                        </AppButton>
                    </div>
                </div>
                <EmptyState v-if="!questions.length" icon="clipboard" :title="$t('exams.noQuestionsTitle')" :message="$t('exams.noQuestionsMessage')">
                    <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
                        <AppButton @click="openQuestion()">
                            <Icon name="plus" :size="15" class="me-1" />
                            {{ $t('exams.addQuestion') }}
                        </AppButton>
                        <AppButton variant="secondary" @click="router.push(`/teacher/exams/${examId}/questions`)">
                            <Icon name="layers" :size="15" class="me-1" />
                            {{ $t('examQuestions.bulkEditor') }}
                        </AppButton>
                    </div>
                </EmptyState>
                <div v-else class="space-y-4">
                    <div v-for="(q, qi) in questions" :key="q.id" class="rounded-xl border border-ink-100 bg-white p-4 sm:p-5 shadow-sm space-y-3">
                        <!-- Question Header Row -->
                        <div class="flex flex-wrap items-center justify-between gap-2.5 pb-3 border-b border-ink-100">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-bold uppercase tracking-wider text-ink-600 bg-ink-100 px-2 py-0.5 rounded">{{ $t('exams.questionNumber', { n: qi + 1 }) }}</span>
                                <AppBadge :tone="q.type === 'essay' ? 'warning' : 'info'">
                                    {{ q.type === 'essay' ? $t('exams.qTypeEssayShort') : (q.type === 'multiple_choice' ? $t('exams.qTypeMultipleShort') : ($t('exams.qTypeChoiceShort') || 'اختيار من متعدد')) }}
                                </AppBadge>
                                <span class="text-xs text-ink-500 font-bold">({{ q.points }} {{ $t('examTake.pts') }})</span>
                            </div>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <AppButton v-if="q.type !== 'essay'" variant="outline" size="sm" class="!px-2.5 !py-1 text-xs" @click="openOption(q)">
                                    + {{ $t('exams.addOption') }}
                                </AppButton>
                                <AppButton
                                    v-if="q.type !== 'essay' && Number(data?.attempts_count) > 0"
                                    variant="secondary"
                                    size="sm"
                                    class="!px-2.5 !py-1 text-xs"
                                    @click="requestQuestionRegrade(q)"
                                >
                                    {{ $t('exams.regradeAction') }}
                                </AppButton>
                                <button class="rounded-lg px-2.5 py-1 text-xs font-medium text-ink-600 hover:bg-ink-100 border border-ink-200" @click="openQuestion(q)">
                                    {{ $t('common.edit') }}
                                </button>
                                <button class="rounded-lg px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 border border-rose-200" @click="confirmTarget = q; runDelete('question')">
                                    {{ $t('common.delete') }}
                                </button>
                            </div>
                        </div>

                        <!-- Question Body -->
                        <div class="pt-1">
                            <p class="font-medium text-ink-900 text-base leading-relaxed break-words" dir="auto">{{ q.question_text }}</p>
                            <img v-if="q.image_url" :src="q.image_url" :alt="$t('examQuestions.questionImage')" class="mt-3 max-h-64 rounded-lg border border-ink-200 bg-white object-contain" />
                            <div v-if="q.reference_answer" class="mt-2 rounded-lg bg-amber-50/80 border border-amber-200 p-3 text-xs text-amber-900">
                                <strong class="block text-amber-800 mb-1">{{ $t('exams.referenceAnswerTeacher') }}</strong>
                                {{ q.reference_answer }}
                            </div>
                        </div>

                        <!-- MCQ Options list -->
                        <div v-if="q.type !== 'essay' && q.options?.length" class="mt-3 space-y-2">
                            <div
                                v-for="o in q.options"
                                :key="o.id"
                                class="rounded-lg border p-3 transition"
                                :class="o.is_correct ? 'border-emerald-300 bg-emerald-50/80 shadow-xs' : 'border-ink-200 bg-ink-50/40 hover:bg-ink-50'"
                            >
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                                    <div class="flex items-start sm:items-center gap-2.5 min-w-0 flex-1">
                                        <span
                                            class="mt-0.5 sm:mt-0 h-4 w-4 shrink-0 rounded-full border-2 flex items-center justify-center transition-colors"
                                            :class="o.is_correct ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-ink-300 bg-white'"
                                        >
                                            <svg v-if="o.is_correct" class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                <path d="M20 6 9 17l-5-5"/>
                                            </svg>
                                        </span>
                                        <span
                                            class="text-sm leading-relaxed break-words flex-1 text-start"
                                            :class="o.is_correct ? 'text-emerald-950 font-bold' : 'text-ink-800'"
                                            dir="auto"
                                        >
                                            {{ o.option_text }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1.5 self-end sm:self-auto shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-ink-100 sm:border-transparent w-full sm:w-auto justify-end">
                                        <button
                                            type="button"
                                            class="rounded px-2.5 py-1 text-xs font-medium transition-colors"
                                            :class="o.is_correct ? 'bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200'"
                                            @click="toggleCorrect(q, o)"
                                        >
                                            <span v-if="o.is_correct">✕ {{ $t('exams.unmark') }}</span>
                                            <span v-else>✓ {{ $t('exams.markCorrect') }}</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded px-2.5 py-1 text-xs font-medium text-ink-600 hover:bg-ink-100 border border-ink-200"
                                            @click="openOption(q, o)"
                                        >
                                            {{ $t('common.edit') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 border border-rose-200"
                                            @click="deleteOption(q, o)"
                                        >
                                            {{ $t('common.delete') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attempts & Grading Tab -->
            <div v-else class="space-y-4">
                <!-- Task toolbar: group, search, export -->
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-ink-100 bg-white p-3 shadow-sm">
                    <div class="flex overflow-hidden rounded-lg border border-ink-200 text-sm">
                        <button type="button" class="px-3 py-1.5 transition font-medium" :class="attemptsView === 'flat' ? 'bg-terracotta-600 text-white' : 'bg-white text-ink-600 hover:bg-ink-50'" @click="switchAttemptsView('flat')">{{ $t('exams.viewAllAttempts') }}</button>
                        <button type="button" class="px-3 py-1.5 transition font-medium" :class="attemptsView === 'student' ? 'bg-terracotta-600 text-white' : 'bg-white text-ink-600 hover:bg-ink-50'" @click="switchAttemptsView('student')">{{ $t('exams.viewByStudent') }}</button>
                    </div>

                    <!-- Search Input for Student Name, Code, Email, Phone -->
                    <div class="relative flex-1 min-w-[15rem]">
                        <input
                            v-model="studentSearch"
                            type="search"
                            class="w-full rounded-lg border border-ink-200 ps-9 pe-8 py-1.5 text-sm focus:border-terracotta-500 focus:outline-none focus:ring-1 focus:ring-terracotta-500"
                            :placeholder="$t('exams.searchStudentsPlaceholder')"
                            @input="onStudentSearch"
                        />
                        <span class="absolute inset-y-0 start-0 flex items-center ps-2.5 pointer-events-none text-ink-400 text-sm">
                            🔍
                        </span>
                        <button
                            v-if="studentSearch"
                            type="button"
                            class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-ink-400 hover:text-ink-700"
                            @click="clearStudentSearch"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="flex flex-wrap items-end gap-2">
                        <AppSelect v-model="statusFilter" :label="$t('exams.filterStatus')" :options="attemptStatusOptions" id="attempt-status-filter" class="min-w-36" @update:model-value="onAttemptFilterChange" />
                        <AppSelect v-model="integrityFilter" :label="$t('exams.filterIntegrity')" :options="integrityStatusOptions" id="attempt-integrity-filter" class="min-w-36" @update:model-value="onAttemptFilterChange" />
                        <AppInput v-model="exactScoreFilter" :label="$t('exams.exactScoreFilter')" type="number" min="0" id="attempt-score-filter" class="w-28" @update:model-value="onAttemptFilterChange" />
                    </div>

                    <div class="ms-auto flex flex-wrap items-center justify-end gap-2">
                        <AppButton v-if="selectedAttemptIds.length" variant="danger" size="sm" @click="deleteSelectedOpen = true">
                            {{ $t('exams.deleteSelectedAttempts', { n: selectedAttemptIds.length }) }}
                        </AppButton>
                        <AppButton variant="outline" size="sm" :loading="exportBusy" @click="exportResults('csv')">⬇ {{ $t('exams.exportCsv') }}</AppButton>
                        <AppButton variant="outline" size="sm" :loading="exportBusy" @click="exportResults('xlsx')">📊 {{ $t('exams.exportXlsx') }}</AppButton>
                        <AppButton variant="outline" size="sm" :loading="exportBusy" @click="exportResults('pdf')">📄 {{ $t('exams.exportPdf') }}</AppButton>
                        <AppButton variant="outline" size="sm" :loading="exportBusy" @click="exportResults('print')">🖨 {{ $t('exams.exportPrint') }}</AppButton>
                    </div>
                </div>

                <!-- Grouped by student: best/latest, integrity, grading state, expandable attempts -->
                <template v-if="attemptsView === 'student'">
                    <p v-if="groupedSummary" class="text-xs text-ink-500">
                        {{ $t('exams.groupedSummary', { students: groupedSummary.students_count, attempts: groupedSummary.attempts_count, pending: groupedSummary.pending_grading_count, flagged: groupedSummary.flagged_count }) }}
                    </p>
                    <LoadingSpinner v-if="groupBusy" />
                    <EmptyState
                        v-else-if="!grouped.length"
                        icon="clipboard"
                        :title="studentSearch ? ($t('students.notFound') || 'لا توجد نتائج مطابقة للبحث') : $t('exams.noAttemptsTitle')"
                        :message="studentSearch ? 'جرب البحث باسم أو كود أو بريد طالب آخر' : $t('exams.noAttemptsMessage')"
                    />
                    <div v-else class="space-y-3">
                        <div v-for="g in grouped" :key="g.student_id" class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                            <button type="button" class="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-start" @click="toggleStudent(g.student_id)">
                                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (g.student?.name || 'U').slice(0, 1) }}</div>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-ink-800" dir="auto">{{ g.student?.name }} ({{ g.student?.student_code || '---' }})</p>
                                    <p class="text-xs text-ink-400">
                                        {{ $t('exams.bestLabel') }} <span class="font-bold text-ink-700">{{ g.best ? `${g.best.percentage}%` : '—' }}</span>
                                        · {{ $t('exams.latestLabel') }} {{ attemptStatusLabel(g.latest?.status) }}
                                        · {{ $t('exams.pendingGrading') }} {{ g.pending_grading_count }}
                                        <span v-if="g.integrity?.flagged_count" class="text-rose-600 font-semibold ms-2">⚠ {{ $t('exams.flaggedCount', { n: g.integrity.flagged_count }) }}</span>
                                        <span v-if="g.integrity?.violation_warnings_total" class="text-amber-600 ms-2">{{ $t('exams.warningsTotal', { n: g.integrity.violation_warnings_total }) }}</span>
                                    </p>
                                </div>
                                <AppBadge :tone="attemptTone(g.latest?.status)">{{ g.attempts_count }} ×</AppBadge>
                                <span class="text-ink-400">{{ expandedStudent === g.student_id ? '▲' : '▼' }}</span>
                            </button>
                            <div v-if="expandedStudent === g.student_id" class="divide-y divide-ink-100 border-t border-ink-100 bg-ink-50/40">
                                <div v-for="a in g.attempts" :key="a.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs text-ink-400">
                                            {{ $t('exams.attemptNumber', { n: a.attempt_number }) }} ·
                                            {{ $t('exams.scoreLabel') }} <span class="font-bold text-ink-700">{{ a.score ?? '—' }}</span> ({{ a.percentage ?? '—' }}%)
                                            · {{ a.outcome }}
                                            <span v-if="a.end_reason" class="ms-1">· {{ a.end_reason }}</span>
                                        </p>
                                    </div>
                                    <AppBadge :tone="attemptTone(a)">{{ attemptStatusLabel(a) }}</AppBadge>
                                    <AppButton variant="outline" size="sm" @click="openGrading(a)">📝 {{ $t('exams.gradeAction') }}</AppButton>
                                    <router-link :to="`/teacher/integrity/attempts/${a.id}`">
                                        <AppButton variant="ghost" size="sm">🔍 {{ $t('exams.integrityAction') }}</AppButton>
                                    </router-link>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="border-t border-ink-100 px-4 py-3">
                        <Pagination v-if="groupedMeta" :meta="groupedMeta" @change="loadGrouped" />
                    </div>
                </template>

                <LoadingSpinner v-else-if="attemptsBusy" />
                <EmptyState
                    v-else-if="!attempts.length"
                    icon="clipboard"
                    :title="studentSearch ? ($t('students.notFound') || 'لا توجد نتائج مطابقة للبحث') : $t('exams.noAttemptsTitle')"
                    :message="studentSearch ? 'جرب البحث باسم أو كود أو بريد طالب آخر' : $t('exams.noAttemptsMessage')"
                />
                <div v-else class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                    <div class="divide-y divide-ink-100">
                        <div v-for="a in attempts" :key="a.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-ink-300 text-rose-600 focus:ring-rose-400"
                                :checked="selectedAttemptIds.includes(Number(a.id))"
                                :disabled="a.status === 'in_progress' && !selectedAttemptIds.includes(Number(a.id))"
                                :aria-label="$t('exams.selectAttempt', { n: a.attempt_number, student: a.student?.name })"
                                @click.stop
                                @change="toggleAttemptSelection(a)"
                            />
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (a.student?.name || 'U').slice(0, 1) }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800" dir="auto">{{ a.student?.name }} ({{ a.student?.student_code || '---' }})</p>
                                <p class="text-xs text-ink-400">
                                    {{ $t('exams.attemptNumber', { n: a.attempt_number }) }} ·
                                    {{ $t('exams.scoreLabel') }} <span class="font-bold text-ink-700">{{ a.score ?? '—' }}</span>
                                    ({{ a.percentage ?? '—' }}%)
                                    <span v-if="a.grades_published_at || a.status === 'published'" class="text-emerald-600 font-semibold ms-2">✓ {{ $t('exams.gradesRecordedBadge') }}</span>
                                    <span v-else-if="a.status === 'in_progress'" class="text-sky-600 font-semibold ms-2">⏱ {{ $t('exams.inProgressNoticeBadge') }}</span>
                                    <span v-else-if="a.status === 'expired'" class="text-rose-600 font-semibold ms-2">⌛ {{ $t('exams.statusExpiredLabel') }}</span>
                                    <span v-else class="text-amber-600 font-semibold ms-2">⏳ {{ $t('exams.gradingDraftBadge') }}</span>
                                </p>
                            </div>
                            <AppBadge :tone="attemptTone(a)">
                                {{ attemptStatusLabel(a) }}
                            </AppBadge>
                            <AppButton variant="outline" size="sm" @click="openGrading(a)">
                                📝 {{ $t('exams.gradeAction') }}
                            </AppButton>
                            <router-link :to="`/teacher/integrity/attempts/${a.id}`">
                                <AppButton variant="ghost" size="sm">🔍 {{ $t('exams.integrityAction') }}</AppButton>
                            </router-link>
                        </div>
                    </div>
                    <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="attemptsMeta" :meta="attemptsMeta" @change="loadAttempts" /></div>
                </div>
            </div>
        </template>

        <!-- Question modal -->
        <AppModal :open="qModal" :title="qForm.id ? $t('exams.editQuestion') : $t('exams.addQuestion')" size="md" @close="qModal = false">
            <form class="space-y-4" @submit.prevent="saveQuestion">
                <AppSelect v-model="qForm.type" :label="$t('exams.questionTypeLabel')" :options="questionTypeOptions" id="q-type" required />
                <AppTextarea v-model="qForm.question_text" :label="$t('exams.questionText')" required id="q-text" :error="qErrors.question_text" :rows="3" />
                <AppInput v-model="qForm.points" :label="$t('exams.points')" type="number" min="1" max="1000" id="q-points" :error="qErrors.points" required />
                <div v-if="qForm.type !== 'essay'" class="space-y-2 rounded-lg border border-ink-100 bg-ink-50 p-3">
                    <label class="flex items-start gap-2 text-sm text-ink-700">
                        <input v-model="qForm.explanation_enabled" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" @change="onExplanationEnabledChange" />
                        <span>{{ $t('examQuestions.explanationEnabled') }}</span>
                    </label>
                    <label v-if="qForm.explanation_enabled" class="ms-6 flex items-start gap-2 text-sm text-ink-600">
                        <input v-model="qForm.explanation_required" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" />
                        <span>{{ $t('examQuestions.explanationRequired') }}</span>
                    </label>
                    <p v-if="qForm.explanation_enabled" class="ms-6 text-xs text-ink-400">{{ $t('examQuestions.explanationHint') }}</p>
                </div>
                <AppTextarea
                    v-if="qForm.type === 'essay'"
                    v-model="qForm.reference_answer"
                    :label="$t('exams.referenceAnswerLabel')"
                    id="q-ref-answer"
                    :rows="3"
                    :placeholder="$t('exams.referenceAnswerPlaceholder')"
                />
                <div class="rounded-lg border border-ink-200 bg-ink-50 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-ink-800">{{ $t('examQuestions.questionImage') }}</p>
                            <p class="text-xs text-ink-500">{{ $t('examQuestions.questionImageHint') }}</p>
                        </div>
                        <label v-if="qForm.id" class="cursor-pointer rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm font-medium text-ink-700 hover:bg-ink-50" :class="qImageBusy ? 'pointer-events-none opacity-60' : ''">
                            {{ qImageBusy ? $t('common.loading') : (qForm.image_url ? $t('examQuestions.replaceImage') : $t('examQuestions.uploadImage')) }}
                            <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only" @change="uploadQuestionImage" />
                        </label>
                    </div>
                    <p v-if="!qForm.id" class="mt-2 text-xs text-amber-700">{{ $t('examQuestions.saveBeforeImage') }}</p>
                    <div v-if="qForm.image_url" class="relative mt-3">
                        <img :src="qForm.image_url" :alt="$t('examQuestions.questionImage')" class="max-h-64 rounded-lg border border-ink-200 bg-white object-contain" />
                        <button type="button" class="absolute end-2 top-2 rounded bg-white px-2 py-1 text-xs font-medium text-rose-600 shadow hover:bg-rose-50" :disabled="qImageBusy" @click="removeQuestionImage">{{ $t('common.remove') }}</button>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <AppButton variant="outline" @click="qModal = false">{{ $t('common.cancel') }}</AppButton>
                    <AppButton type="submit" :loading="qBusy">{{ $t('common.save') }}</AppButton>
                </div>
            </form>
        </AppModal>

        <!-- Option modal -->
        <AppModal :open="oModal" :title="oForm.id ? $t('exams.editOption') : $t('exams.addOption')" size="md" @close="oModal = false">
            <form class="space-y-4" @submit.prevent="saveOption">
                <AppInput v-model="oForm.option_text" :label="$t('exams.optionText')" required id="o-text" :error="oErrors.option_text" />
                <label class="flex items-center gap-2 text-sm text-ink-700">
                    <input type="checkbox" v-model="oForm.is_correct" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" />
                    {{ $t('exams.correctAnswer') }}
                </label>
                <div class="flex justify-end gap-2">
                    <AppButton variant="outline" @click="oModal = false">{{ $t('common.cancel') }}</AppButton>
                    <AppButton type="submit" :loading="oBusy">{{ $t('common.save') }}</AppButton>
                </div>
            </form>
        </AppModal>

        <!-- Essay Grading & Publication Modal -->
        <AppModal :open="Boolean(gradingAttempt)" :title="$t('exams.gradingModalTitle') + (gradingAttempt?.student?.name || '')" size="lg" @close="gradingAttempt = null">
            <LoadingSpinner v-if="gradingLoading" />
            <div v-else-if="gradingData" class="space-y-6">
                <div class="rounded-xl border border-ink-200 bg-ink-50/50 p-4 flex justify-between items-center">
                    <div>
                        <p class="text-sm font-bold text-ink-900">{{ gradingData.student?.name }} ({{ gradingData.student?.student_code }})</p>
                        <p class="text-xs text-ink-500">{{ $t('exams.gradesStatusLabel') }} {{ gradingData.grades_published ? $t('exams.gradesStatusPublished') : $t('exams.gradesStatusDraft') }}</p>
                    </div>
                    <div class="text-left">
                        <span class="text-2xl font-black text-terracotta-700">{{ gradingData.score ?? 0 }}</span>
                        <span class="text-xs text-ink-500 font-medium"> / {{ gradingData.total_points ?? totalMarks }}</span>
                        <span class="text-xs text-ink-400 block">{{ $t('exams.totalScorePct', { pct: gradingData.percentage ?? 0 }) }}</span>
                    </div>
                </div>

                <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
                    <div v-for="(q, index) in (gradingData.questions?.length ? gradingData.questions : questions)" :key="q.id" class="rounded-xl border border-ink-200 p-4 space-y-3 bg-white">
                        <div class="flex justify-between items-start gap-2">
                            <div>
                                <span class="text-xs font-bold text-ink-500">{{ $t('exams.questionNumber', { n: index + 1 }) }} ({{ (q.question_type || q.type) === 'essay' ? $t('exams.qTypeEssayShort') : $t('exams.qTypeChoiceShort') }}) — {{ $t('exams.totalPointsLabel') }}: {{ q.points }}</span>
                                <p class="text-sm font-semibold text-ink-900 mt-1" dir="auto">{{ q.question_text }}</p>
                            </div>
                        </div>

                        <!-- Reference answer if essay -->
                        <div v-if="q.reference_answer || questions.find(x => x.id === q.id)?.reference_answer" class="rounded bg-amber-50 p-2.5 text-xs text-amber-900">
                            <strong>{{ $t('exams.referenceAnswerShort') }}</strong> {{ q.reference_answer || questions.find(x => x.id === q.id)?.reference_answer }}
                        </div>

                        <!-- Student Answer -->
                        <div class="rounded-lg bg-ink-50 p-3 text-sm">
                            <span class="text-xs font-medium text-ink-500 block mb-1">{{ $t('exams.studentAnswerLabel') }}</span>
                            <div v-if="(q.question_type || q.type) === 'essay'" class="text-ink-900 font-mono whitespace-pre-wrap" dir="auto">
                                {{ q.answer_text || (gradingData.answers?.find(a => a.question_id === q.id))?.answer_text || $t('exams.noAnswerEntered') }}
                            </div>
                            <div v-else class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <span :class="(q.is_correct ?? (gradingData.answers?.find(a => a.question_id === q.id))?.is_correct) ? 'text-emerald-700 font-bold' : 'text-rose-700 font-bold'">
                                        {{ (q.is_correct ?? (gradingData.answers?.find(a => a.question_id === q.id))?.is_correct) ? '✓ ' + $t('exams.answerCorrect') : '✗ ' + $t('exams.answerIncorrect') }}
                                    </span>
                                    <span class="text-xs text-ink-500 font-medium">
                                        ({{ $t('exams.pointsEarnedOf', { earned: q.points_earned ?? (gradingData.answers?.find(a => a.question_id === q.id))?.points_earned ?? 0, total: q.points }) }})
                                    </span>
                                </div>
                                <!-- Option breakdown if available from snapshot -->
                                <div v-if="q.options?.length" class="mt-2 space-y-1.5">
                                    <div
                                        v-for="opt in q.options"
                                        :key="opt.id"
                                        class="flex items-center justify-between rounded-lg px-3 py-2 text-xs"
                                        :class="opt.is_selected
                                            ? (opt.is_correct ? 'bg-emerald-50 text-emerald-900 border border-emerald-200 font-medium' : 'bg-rose-50 text-rose-900 border border-rose-200 font-medium')
                                            : (opt.is_correct ? 'bg-emerald-50/50 text-emerald-800 border border-dashed border-emerald-300' : 'bg-white text-ink-700 border border-ink-100')"
                                    >
                                        <div class="flex items-center gap-2">
                                            <span v-if="opt.is_selected" class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider" :class="opt.is_correct ? 'bg-emerald-200 text-emerald-800' : 'bg-rose-200 text-rose-800'">
                                                {{ $t('exams.studentAnswerLabel') }}
                                            </span>
                                            <span dir="auto">{{ opt.option_text }}</span>
                                        </div>
                                        <span v-if="opt.is_correct" class="text-emerald-700 font-semibold text-[11px]">
                                            ✓ {{ $t('exams.correctAnswer') }}
                                        </span>
                                    </div>
                                </div>
                                <div v-if="studentExplanation(q).trim()" class="mt-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2.5">
                                    <span class="mb-1 block text-xs font-semibold text-sky-800">{{ $t('exams.studentExplanation') }}</span>
                                    <p class="whitespace-pre-wrap text-sm text-sky-950" dir="auto">{{ studentExplanation(q) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Grading Controls for Essay -->
                        <div v-if="(q.question_type || q.type) === 'essay'" class="border-t pt-3 space-y-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <AppInput v-model="gradeForm[q.id]" type="number" min="0" :max="q.points" :label="$t('exams.awardedPoints')" id="essay-pts" />
                                <AppInput v-model="gradeFeedback[q.id]" :label="$t('exams.teacherFeedbackLabel')" id="essay-fb" />
                            </div>
                            <div class="flex justify-end">
                                <AppButton size="sm" variant="outline" :loading="gradingBusy" @click="submitEssayGrade(q.id)">{{ $t('exams.saveQuestionGrade') }}</AppButton>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center border-t pt-4">
                    <AppButton variant="outline" @click="gradingAttempt = null">{{ $t('common.close') }}</AppButton>
                    <AppButton variant="success" :loading="publishBusy" @click="submitPublishGrades">
                        🚀 {{ $t('exams.publishGradesAction') }}
                    </AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Integrity settings modal -->
        <AppModal :open="makeUpModal" :title="$t('exams.makeUpTitle')" size="lg" @close="makeUpModal = false">
            <div class="space-y-5">
                <p class="text-sm leading-relaxed text-ink-600">{{ $t('exams.makeUpHint') }}</p>

                <div v-if="makeUpAssignments.length" class="rounded-xl border border-ink-100">
                    <h3 class="border-b border-ink-100 px-4 py-3 text-sm font-semibold text-ink-800">{{ $t('exams.makeUpHistory') }}</h3>
                    <div class="divide-y divide-ink-100">
                        <div v-for="assignment in makeUpAssignments" :key="assignment.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink-800" dir="auto">{{ assignment.student?.name || assignment.student_id }}</p>
                                <p class="text-xs text-ink-400">
                                    {{ makeUpStatusLabel(assignment.status) }}
                                    <span v-if="assignment.reason"> · {{ assignment.reason }}</span>
                                    <span v-if="assignment.attempt_id"> · {{ $t('exams.makeUpAttemptLinked', { id: assignment.attempt_id }) }}</span>
                                </p>
                            </div>
                            <AppButton v-if="assignment.status === 'assigned'" variant="ghost" size="sm" class="text-rose-600" @click="requestRevokeMakeUp(assignment)">{{ $t('exams.revokeMakeUp') }}</AppButton>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <AppInput v-model="makeUpStudentSearch" :label="$t('exams.makeUpSearchStudents')" :placeholder="$t('exams.searchStudentsPlaceholder')" id="make-up-student-search" class="flex-1" @update:model-value="onMakeUpStudentSearch" />
                        <span class="text-xs text-ink-400">{{ $t('exams.makeUpSelectedCount', { n: selectedMakeUpStudentIds.length }) }}</span>
                    </div>
                    <LoadingSpinner v-if="makeUpLoadBusy" />
                    <EmptyState v-else-if="!makeUpStudents.length" icon="users" :title="$t('exams.makeUpNoStudentsTitle')" :message="$t('exams.makeUpNoStudentsMessage')" />
                    <div v-else class="max-h-64 overflow-y-auto rounded-xl border border-ink-100">
                        <label v-for="enrollment in makeUpStudents" :key="enrollment.student_id" class="flex items-center gap-3 border-b border-ink-100 px-4 py-3 last:border-0" :class="activeMakeUpStudentIds.has(Number(enrollment.student_id)) ? 'bg-ink-50 opacity-70' : 'hover:bg-ink-50'">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400"
                                :checked="selectedMakeUpStudentIds.includes(Number(enrollment.student_id)) || activeMakeUpStudentIds.has(Number(enrollment.student_id))"
                                :disabled="activeMakeUpStudentIds.has(Number(enrollment.student_id)) && !selectedMakeUpStudentIds.includes(Number(enrollment.student_id))"
                                @change="toggleMakeUpStudent(enrollment.student_id)"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-ink-800" dir="auto">{{ enrollment.student?.name }}</span>
                                <span class="block text-xs text-ink-400">{{ enrollment.student?.student_code || enrollment.student?.email }}</span>
                            </span>
                            <AppBadge v-if="activeMakeUpStudentIds.has(Number(enrollment.student_id))" tone="success">{{ $t('exams.makeUpAlreadyAssigned') }}</AppBadge>
                        </label>
                    </div>
                    <Pagination v-if="makeUpStudentsMeta" :meta="makeUpStudentsMeta" @change="loadMakeUpStudents" />
                    <AppTextarea v-model="makeUpReason" :label="$t('exams.makeUpReason')" :rows="2" id="make-up-reason" :placeholder="$t('exams.makeUpReasonPlaceholder')" />
                </div>
            </div>
            <template #footer>
                <AppButton variant="outline" :disabled="makeUpAssignBusy" @click="makeUpModal = false">{{ $t('common.close') }}</AppButton>
                <AppButton :loading="makeUpAssignBusy" :disabled="!selectedMakeUpStudentIds.length || makeUpLoadBusy" @click="assignMakeUps">{{ $t('exams.assignMakeUpSelected') }}</AppButton>
            </template>
        </AppModal>

        <AppModal :open="intModal" :title="$t('exams.settingsTitle')" size="md" @close="intModal = false">
            <form class="space-y-3" @submit.prevent="saveIntegrity">
                <label v-for="f in integrityFields" :key="f.k" class="flex items-center justify-between rounded-lg border border-ink-100 px-3 py-2.5 text-sm text-ink-700">
                    <span>{{ f.label }}</span>
                    <input type="checkbox" v-model="intForm[f.k]" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" />
                </label>
                <!-- Interruption policy (P0.5): warnings 1..N, only the next one ends the attempt -->
                <div class="rounded-lg border border-ink-100 px-3 py-2.5">
                    <AppInput
                        v-model="intForm.violation_warning_threshold"
                        :label="$t('exams.violationWarningThreshold')"
                        type="number"
                        id="int-warning-threshold"
                        :hint="$t('exams.violationWarningThresholdHint')"
                    />
                </div>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="intModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="intBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <ConfirmDialog :open="deleteOpen" :title="$t('exams.deleteTitle')" :message="$t('exams.deleteMessage', { title: data?.title })" :confirm-text="$t('common.delete')" :loading="deleteBusy" @close="deleteOpen = false" @confirm="remove" />
        <ConfirmDialog :open="Boolean(confirmTarget)" :title="$t('common.confirmDelete')" :message="$t('exams.deleteQuestionMessage')" :confirm-text="$t('common.delete')" :loading="confirmBusy" @close="confirmTarget = null" @confirm="runDelete('question')" />
        <ConfirmDialog
            :open="Boolean(revokeMakeUpTarget)"
            :title="$t('exams.revokeMakeUpTitle')"
            :message="$t('exams.revokeMakeUpMessage', { name: revokeMakeUpTarget?.student?.name || '' })"
            :confirm-text="$t('exams.revokeMakeUp')"
            :loading="revokeMakeUpBusy"
            @close="revokeMakeUpTarget = null"
            @confirm="revokeMakeUp"
        />
        <ConfirmDialog
            :open="Boolean(regradeTarget)"
            :title="$t('exams.regradeConfirmTitle')"
            :message="$t('exams.regradeConfirmMessage')"
            :confirm-text="$t('exams.regradeConfirmAction')"
            tone="primary"
            :loading="regradeBusy"
            @close="regradeTarget = null"
            @confirm="regradeQuestionAttempts"
        />
        <ConfirmDialog
            :open="deleteSelectedOpen"
            :title="$t('exams.deleteSelectedAttemptsTitle')"
            :message="$t('exams.deleteSelectedAttemptsMessage', { n: selectedAttemptIds.length })"
            :confirm-text="$t('exams.deleteSelectedAttemptsConfirm')"
            :loading="deleteAttemptsBusy"
            @close="deleteSelectedOpen = false"
            @confirm="deleteSelectedAttempts"
        />
    </div>
</template>
