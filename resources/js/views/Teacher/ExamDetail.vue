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
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppModal from '@/components/ui/AppModal.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';

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

async function loadAttempts(p = 1) {
    const res = toList(await teacher.examAttempts(examId, { per_page: 15, page: p }));
    attempts.value = res.items;
    attemptsMeta.value = res.meta;
}
async function loadIntegrity() {
    try { integrity.value = await teacher.integritySettings(examId); }
    catch { integrity.value = null; }
}

// `questions` are a nested resource collection; normalise each question's options.
const questions = computed(() => {
    const qs = data.value?.questions;
    if (!qs) return [];
    const arr = Array.isArray(qs) ? qs : (qs.data || []);
    return arr.map((q) => ({ ...q, options: Array.isArray(q.options) ? q.options : (q.options?.data || []) }));
});

const tabs = computed(() => [
    { key: 'questions', label: t('exams.questionsTab') },
    { key: 'attempts', label: t('exams.attemptsTab') },
]);

const integrityFields = computed(() => [
    { k: 'fullscreen_required', label: t('exams.fullscreenRequired') },
    { k: 'prevent_copy', label: t('exams.blockCopy') },
    { k: 'prevent_paste', label: t('exams.blockPaste') },
    { k: 'prevent_context_menu', label: t('exams.blockContext') },
    { k: 'detect_tab_switch', label: t('exams.detectTab') },
    { k: 'detect_window_blur', label: t('exams.detectBlur') },
    { k: 'detect_keyboard_shortcuts', label: t('exams.detectShortcuts') },
]);

// ---- Publish/archive/delete ----
const statusBusy = ref(false);
async function setStatus(kind) {
    statusBusy.value = true;
    try {
        await (kind === 'publish' ? teacher.publishExam : teacher.archiveExam)(examId);
        toast.success(kind === 'publish' ? t('exams.publishedToast') : t('exams.archived'));
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
const qForm = reactive({ id: null, question_text: '', points: 1 });
const qErrors = ref({});
const qBusy = ref(false);
function openQuestion(q = null) {
    qForm.id = q?.id || null;
    qForm.question_text = q?.question_text || '';
    qForm.points = q?.points || 1;
    qErrors.value = {};
    qModal.value = true;
}
async function saveQuestion() {
    qBusy.value = true;
    qErrors.value = {};
    try {
        if (qForm.id) await teacher.updateQuestion(qForm.id, { question_text: qForm.question_text, points: qForm.points });
        else await teacher.createQuestion(examId, { question_text: qForm.question_text, points: qForm.points, type: 'single_choice' });
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
const intForm = reactive({ fullscreen_required: true, prevent_copy: true, prevent_paste: true, prevent_context_menu: true, detect_tab_switch: true, detect_window_blur: true, detect_keyboard_shortcuts: true });
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
    return { submitted: 'success', in_progress: 'warning', expired: 'danger' }[status] || 'neutral';
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
                <div class="flex justify-end"><AppButton @click="openQuestion()">{{ $t('exams.addQuestion') }}</AppButton></div>
                <EmptyState v-if="!questions.length" icon="clipboard" :title="$t('exams.noQuestionsTitle')" :message="$t('exams.noQuestionsMessage')">
                    <AppButton @click="openQuestion()">{{ $t('exams.addQuestion') }}</AppButton>
                </EmptyState>
                <div v-else class="space-y-4">
                    <div v-for="(q, qi) in questions" :key="q.id" class="rounded-xl border border-ink-100 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-ink-400">{{ $t('exams.questionMeta', { n: qi + 1, points: q.points }) }}</p>
                                <p class="mt-1 font-medium text-ink-900" dir="auto">{{ q.question_text }}</p>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100" @click="openQuestion(q)">{{ $t('common.edit') }}</button>
                                <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="confirmTarget = q; runDelete('question')">{{ $t('common.delete') }}</button>
                                <AppButton variant="outline" size="sm" @click="openOption(q)">{{ $t('exams.addOption') }}</AppButton>
                            </div>
                        </div>
                        <div v-if="q.options?.length" class="mt-3 space-y-2">
                            <div v-for="o in q.options" :key="o.id" class="flex items-center gap-3 rounded-lg border px-3 py-2" :class="o.is_correct ? 'border-emerald-300 bg-emerald-50' : 'border-ink-100 bg-ink-50/40'">
                                <span class="h-3 w-3 shrink-0 rounded-full" :class="o.is_correct ? 'bg-emerald-500' : 'bg-ink-300'" />
                                <span class="flex-1 text-sm text-ink-800" :class="o.is_correct ? 'text-emerald-800' : ''" dir="auto">{{ o.option_text }}</span>
                                <button class="rounded px-2 py-1 text-xs font-medium" :class="o.is_correct ? 'text-rose-600 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50'" @click="toggleCorrect(q, o)">{{ o.is_correct ? $t('exams.unmark') : $t('exams.markCorrect') }}</button>
                                <button class="rounded px-2 py-1 text-xs font-medium text-ink-500 hover:bg-ink-100" @click="openOption(q, o)">{{ $t('common.edit') }}</button>
                                <button class="rounded px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="deleteOption(q, o)">{{ $t('common.delete') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="space-y-4">
                <EmptyState v-if="!attempts.length" icon="clipboard" :title="$t('exams.noAttemptsTitle')" :message="$t('exams.noAttemptsMessage')" />
                <div v-else class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                    <div class="divide-y divide-ink-100">
                        <div v-for="a in attempts" :key="a.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (a.student?.name || 'U').slice(0, 1) }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800" dir="auto">{{ a.student?.name }}</p>
                                <p class="text-xs text-ink-400">{{ $t('common.attemptN', { n: a.attempt_number }) }} · {{ a.percentage ?? '—' }}%</p>
                            </div>
                            <AppBadge :tone="attemptTone(a.status)">{{ $t(`status.${a.status}`, a.status) }}</AppBadge>
                            <router-link :to="`/teacher/integrity/attempts/${a.id}`"><AppButton variant="outline" size="sm">{{ $t('integrity.review') }}</AppButton></router-link>
                        </div>
                    </div>
                    <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="attemptsMeta" :meta="attemptsMeta" @change="loadAttempts" /></div>
                </div>
            </div>
        </template>

        <!-- Question modal -->
        <AppModal :open="qModal" :title="qForm.id ? $t('exams.editQuestion') : $t('exams.addQuestion')" size="md" @close="qModal = false">
            <form class="space-y-4" @submit.prevent="saveQuestion">
                <AppTextarea v-model="qForm.question_text" :label="$t('exams.questionText')" required id="q-text" :error="qErrors.question_text" :rows="3" />
                <AppInput v-model="qForm.points" :label="$t('exams.points')" type="number" id="q-points" :error="qErrors.points" />
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="qModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="qBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Option modal -->
        <AppModal :open="oModal" :title="oForm.id ? $t('exams.editOption') : $t('exams.addOption')" size="md" @close="oModal = false">
            <form class="space-y-4" @submit.prevent="saveOption">
                <AppInput v-model="oForm.option_text" :label="$t('exams.optionText')" required id="o-text" :error="oErrors.option_text" />
                <label class="flex items-center gap-2 text-sm text-ink-700"><input type="checkbox" v-model="oForm.is_correct" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" /> {{ $t('exams.correctAnswer') }}</label>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="oModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="oBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
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
