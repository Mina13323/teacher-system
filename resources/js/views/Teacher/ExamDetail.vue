<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
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
const { fieldErrors } = useFieldErrors();

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

async function loadAttempts(p = 1) {
    const res = toList(await teacher.examAttempts(examId, { per_page: 15, page: p }));
    attempts.value = res.items;
    attemptsMeta.value = res.meta;
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
const qForm = reactive({ id: null, question_text: '', type: 'single_choice', points: 1, reference_answer: '' });
const qErrors = ref({});
const qBusy = ref(false);
function openQuestion(q = null) {
    qForm.id = q?.id || null;
    qForm.question_text = q?.question_text || '';
    qForm.type = q?.type || 'single_choice';
    qForm.points = q?.points || 1;
    qForm.reference_answer = q?.reference_answer || '';
    qErrors.value = {};
    qModal.value = true;
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

// ---- Option modal ----
const oModal = ref(false);
const oTarget = ref(null);
const oForm = reactive({ id: null, option_text: '', is_correct: false });
const oErrors = ref({});
const oBusy = ref(false);
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
        if (gradingData.value?.answers) {
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
const intForm = reactive({ fullscreen_required: true, prevent_copy: true, prevent_paste: true, prevent_context_menu: true, detect_tab_switch: true, detect_window_blur: true, detect_keyboard_shortcuts: true, terminate_on_violation: true });
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
    }
    intModal.value = true;
}
async function saveIntegrity() {
    intBusy.value = true;
    try {
        await teacher.updateIntegritySettings(examId, intForm);
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

function attemptTone(status) {
    return { published: 'success', submitted: 'info', grading: 'warning', in_progress: 'warning', expired: 'danger' }[status] || 'neutral';
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
                    <AppButton @click="openQuestion()">{{ $t('exams.addQuestion') }}</AppButton>
                </EmptyState>
                <div v-else class="space-y-4">
                    <div v-for="(q, qi) in questions" :key="q.id" class="rounded-xl border border-ink-100 bg-white p-5 shadow-sm space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold uppercase tracking-wider text-ink-500">{{ $t('exams.questionNumber', { n: qi + 1 }) }}</span>
                                    <AppBadge :tone="q.type === 'essay' ? 'warning' : 'info'">
                                        {{ q.type === 'essay' ? $t('exams.qTypeEssay') : (q.type === 'multiple_choice' ? $t('exams.qTypeMultipleShort') : $t('exams.qTypeSingle')) }}
                                    </AppBadge>
                                    <span class="text-xs text-ink-500 font-bold">({{ q.points }} {{ $t('examTake.pts') }})</span>
                                </div>
                                <p class="mt-2 font-medium text-ink-900 text-base" dir="auto">{{ q.question_text }}</p>
                                <div v-if="q.reference_answer" class="mt-2 rounded-lg bg-amber-50/80 border border-amber-200 p-3 text-xs text-amber-900">
                                    <strong class="block text-amber-800 mb-1">{{ $t('exams.referenceAnswerTeacher') }}</strong>
                                    {{ q.reference_answer }}
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100" @click="openQuestion(q)">{{ $t('common.edit') }}</button>
                                <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="confirmTarget = q; runDelete('question')">{{ $t('common.delete') }}</button>
                                <AppButton v-if="q.type !== 'essay'" variant="outline" size="sm" @click="openOption(q)">{{ $t('exams.addOption') }}</AppButton>
                            </div>
                        </div>

                        <!-- MCQ Options list -->
                        <div v-if="q.type !== 'essay' && q.options?.length" class="mt-3 space-y-2">
                            <div v-for="o in q.options" :key="o.id" class="flex items-center gap-3 rounded-lg border px-3 py-2" :class="o.is_correct ? 'border-emerald-300 bg-emerald-50' : 'border-ink-100 bg-ink-50/40'">
                                <span class="h-3 w-3 shrink-0 rounded-full" :class="o.is_correct ? 'bg-emerald-500' : 'bg-ink-300'" />
                                <span class="flex-1 text-sm text-ink-800" :class="o.is_correct ? 'text-emerald-800 font-bold' : ''" dir="auto">{{ o.option_text }}</span>
                                <button class="rounded px-2 py-1 text-xs font-medium" :class="o.is_correct ? 'text-rose-600 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50'" @click="toggleCorrect(q, o)">{{ o.is_correct ? $t('exams.unmark') : $t('exams.markCorrect') }}</button>
                                <button class="rounded px-2 py-1 text-xs font-medium text-ink-500 hover:bg-ink-100" @click="openOption(q, o)">{{ $t('common.edit') }}</button>
                                <button class="rounded px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="deleteOption(q, o)">{{ $t('common.delete') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attempts & Grading Tab -->
            <div v-else class="space-y-4">
                <EmptyState v-if="!attempts.length" icon="clipboard" :title="$t('exams.noAttemptsTitle')" :message="$t('exams.noAttemptsMessage')" />
                <div v-else class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                    <div class="divide-y divide-ink-100">
                        <div v-for="a in attempts" :key="a.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (a.student?.name || 'U').slice(0, 1) }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800" dir="auto">{{ a.student?.name }} ({{ a.student?.student_code || '---' }})</p>
                                <p class="text-xs text-ink-400">
                                    {{ $t('exams.attemptNumber', { n: a.attempt_number }) }} ·
                                    {{ $t('exams.scoreLabel') }} <span class="font-bold text-ink-700">{{ a.score ?? '—' }}</span>
                                    ({{ a.percentage ?? '—' }}%)
                                    <span v-if="a.grades_published_at" class="text-emerald-600 font-semibold ms-2">✓ {{ $t('exams.gradesRecordedBadge') }}</span>
                                    <span v-else class="text-amber-600 font-semibold ms-2">⏳ {{ $t('exams.gradingDraftBadge') }}</span>
                                </p>
                            </div>
                            <AppBadge :tone="attemptTone(a.status)">
                                {{ a.status === 'published' ? $t('exams.statusPublishedLabel') : (a.status === 'grading' ? $t('exams.statusGradingLabel') : a.status) }}
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
                <AppTextarea
                    v-if="qForm.type === 'essay'"
                    v-model="qForm.reference_answer"
                    :label="$t('exams.referenceAnswerLabel')"
                    id="q-ref-answer"
                    :rows="3"
                    :placeholder="$t('exams.referenceAnswerPlaceholder')"
                />
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
                        <span class="text-xs text-ink-400 block">{{ $t('exams.totalScorePct', { pct: gradingData.percentage }) }}</span>
                    </div>
                </div>

                <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
                    <div v-for="(q, index) in questions" :key="q.id" class="rounded-xl border border-ink-200 p-4 space-y-3 bg-white">
                        <div class="flex justify-between items-start gap-2">
                            <div>
                                <span class="text-xs font-bold text-ink-500">{{ $t('exams.questionNumber', { n: index + 1 }) }} ({{ q.type === 'essay' ? $t('exams.qTypeEssayShort') : $t('exams.qTypeChoiceShort') }}) — {{ $t('exams.totalPointsLabel') }}: {{ q.points }}</span>
                                <p class="text-sm font-semibold text-ink-900 mt-1" dir="auto">{{ q.question_text }}</p>
                            </div>
                        </div>

                        <!-- Reference answer if essay -->
                        <div v-if="q.reference_answer" class="rounded bg-amber-50 p-2.5 text-xs text-amber-900">
                            <strong>{{ $t('exams.referenceAnswerShort') }}</strong> {{ q.reference_answer }}
                        </div>

                        <!-- Student Answer -->
                        <div class="rounded-lg bg-ink-50 p-3 text-sm">
                            <span class="text-xs font-medium text-ink-500 block mb-1">{{ $t('exams.studentAnswerLabel') }}</span>
                            <div v-if="q.type === 'essay'" class="text-ink-900 font-mono whitespace-pre-wrap" dir="auto">
                                {{ (gradingData.answers?.find(a => a.question_id === q.id))?.answer_text || $t('exams.noAnswerEntered') }}
                            </div>
                            <div v-else class="text-ink-900 font-medium" dir="auto">
                                <span :class="(gradingData.answers?.find(a => a.question_id === q.id))?.is_correct ? 'text-emerald-700 font-bold' : 'text-rose-700 font-bold'">
                                    {{ (gradingData.answers?.find(a => a.question_id === q.id))?.is_correct ? '✓ ' + $t('exams.answerCorrect') : '✗ ' + $t('exams.answerIncorrect') }}
                                </span>
                                ({{ $t('exams.pointsEarnedOf', { earned: (gradingData.answers?.find(a => a.question_id === q.id))?.points_earned ?? 0, total: q.points }) }})
                            </div>
                        </div>

                        <!-- Grading Controls for Essay -->
                        <div v-if="q.type === 'essay'" class="border-t pt-3 space-y-3">
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
        <AppModal :open="intModal" :title="$t('exams.settingsTitle')" size="md" @close="intModal = false">
            <form class="space-y-3" @submit.prevent="saveIntegrity">
                <label v-for="f in integrityFields" :key="f.k" class="flex items-center justify-between rounded-lg border border-ink-100 px-3 py-2.5 text-sm text-ink-700">
                    <span>{{ f.label }}</span>
                    <input type="checkbox" v-model="intForm[f.k]" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" />
                </label>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="intModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="intBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <ConfirmDialog :open="deleteOpen" :title="$t('exams.deleteTitle')" :message="$t('exams.deleteMessage', { title: data?.title })" :confirm-text="$t('common.delete')" :loading="deleteBusy" @close="deleteOpen = false" @confirm="remove" />
        <ConfirmDialog :open="Boolean(confirmTarget)" :title="$t('common.confirmDelete')" :message="$t('exams.deleteQuestionMessage')" :confirm-text="$t('common.delete')" :loading="confirmBusy" @close="confirmTarget = null" @confirm="runDelete('question')" />
    </div>
</template>
