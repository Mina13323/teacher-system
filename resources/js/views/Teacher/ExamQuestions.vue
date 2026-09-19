<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppModal from '@/components/ui/AppModal.vue';
import Icon from '@/components/ui/Icon.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';

const route = useRoute();
const router = useRouter();
const { t, te } = useI18n();
const toast = useToast();

const examId = route.params.id;
const exam = ref(null);
const questions = ref([]);
const loading = ref(true);
const loadError = ref('');
const saving = ref(false);
const dirty = ref(false);

const isEssay = (q) => q.type === 'essay';

// ---- Load ------------------------------------------------------------------
async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        exam.value = await teacher.exam(examId);
        const res = await teacher.questions(examId);
        const list = Array.isArray(res) ? res : (res?.data || []);
        questions.value = list.map((q) => {
            const row = normalize(q);
            row._key = row.id;
            return row;
        });
        dirty.value = false;
    } catch (e) {
        loadError.value = e.message;
    } finally {
        loading.value = false;
    }
}

function normalize(q) {
    const options = Array.isArray(q.options) ? q.options : (q.options?.data || []);
    return {
        id: q.id,
        question_text: q.question_text || '',
        image_url: q.image_url || null,
        type: q.type || 'single_choice',
        points: q.points ?? 1,
        reference_answer: q.reference_answer || '',
        options: options.map((o) => ({
            id: o.id,
            option_text: o.option_text || '',
            is_correct: Boolean(o.is_correct),
        })),
    };
}

// A freshly created question gets a negative temporary id so Vue has a stable
// key before the server assigns a real one.
let tempId = -1;
function markDirty() {
    dirty.value = true;
}

// ---- Completeness ----------------------------------------------------------
// Mirrors PublishExamAction::assertValid so the teacher can see exactly what is
// still missing before they try to publish and get a 422.
function issuesFor(q) {
    const problems = [];

    if (!String(q.question_text || '').trim()) problems.push('text');

    if (isEssay(q)) {
        if (!(Number(q.points) > 0)) problems.push('points');
        return problems;
    }

    const filled = q.options.filter((o) => String(o.option_text || '').trim());
    if (filled.length < 2) problems.push('options');
    if (!filled.some((o) => o.is_correct)) problems.push('correct');

    return problems;
}

const completeCount = computed(() => questions.value.filter((q) => issuesFor(q).length === 0).length);
const totalPoints = computed(() => questions.value.reduce((sum, q) => sum + (Number(q.points) || 0), 0));
const progressPct = computed(() => {
    if (!questions.value.length) return 0;
    return Math.round((completeCount.value / questions.value.length) * 100);
});

// ---- Editing ---------------------------------------------------------------
const expanded = ref(new Set());
function toggle(q) {
    const next = new Set(expanded.value);
    next.has(q._key) ? next.delete(q._key) : next.add(q._key);
    expanded.value = next;
}
function expandAll() {
    expanded.value = new Set(questions.value.map((q) => q._key));
}
function collapseAll() {
    expanded.value = new Set();
}

const typeOptions = computed(() => [
    { value: 'single_choice', label: t('exams.qTypeSingle') },
    { value: 'multiple_choice', label: t('exams.qTypeMultiple') },
    { value: 'essay', label: t('exams.qTypeEssay') },
]);

function changeType(q, next) {
    q.type = next;
    if (next === 'essay') {
        // An essay question carries no answer key. Drop the options rather than
        // leaving a stale set that would resurface if the type changes back.
        q.options = [];
    } else if (q.options.length < 2) {
        while (q.options.length < 4) q.options.push({ id: null, option_text: '', is_correct: false });
    }
    markDirty();
}

function addQuestion(type = 'single_choice') {
    const q = normalize({ id: tempId--, question_text: '', type, points: 1 });
    // A row that has never been saved still needs a unique key for the list.
    q._key = `tmp-${q.id}`;
    if (type !== 'essay') {
        for (let i = 0; i < 4; i++) q.options.push({ id: null, option_text: '', is_correct: false });
    }
    questions.value.push(q);
    expanded.value = new Set([...expanded.value, q._key]);
    markDirty();
}

const deleteTarget = ref(null);
const deleteBusy = ref(false);
function confirmDeleteQuestion(q) {
    deleteTarget.value = q;
}
async function runDeleteQuestion() {
    const q = deleteTarget.value;
    deleteBusy.value = true;
    try {
        // Only questions the server knows about need a DELETE; a row that was
        // never saved is simply dropped from the local list.
        if (q.id > 0) await teacher.deleteQuestion(q.id);
        questions.value = questions.value.filter((item) => item !== q);
        toast.success(t('common.deleted'));
        deleteTarget.value = null;
    } catch (e) {
        toast.error(e.message);
    } finally {
        deleteBusy.value = false;
    }
}

function addOption(q) {
    q.options.push({ id: null, option_text: '', is_correct: false });
    markDirty();
}
function removeOption(q, option) {
    q.options = q.options.filter((o) => o !== option);
    markDirty();
}

async function uploadImage(q, event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (!(q.id > 0)) {
        toast.info(t('examQuestions.saveBeforeImage'));
        return;
    }
    try {
        const updated = await teacher.uploadQuestionImage(q.id, file);
        q.image_url = updated.image_url;
        toast.success(t('examQuestions.imageUploaded'));
    } catch (e) {
        toast.error(e.message);
    }
}

async function removeImage(q) {
    try {
        await teacher.removeQuestionImage(q.id);
        q.image_url = null;
        toast.success(t('examQuestions.imageRemoved'));
    } catch (e) {
        toast.error(e.message);
    }
}

// Single-choice behaves like a radio: marking one correct clears the others.
// Multiple-choice lets several stay ticked.
function markCorrect(q, option, value) {
    if (q.type === 'single_choice' && value) {
        q.options.forEach((o) => { o.is_correct = o === option; });
    } else {
        option.is_correct = value;
    }
    markDirty();
}

// ---- Save ------------------------------------------------------------------
async function saveAll() {
    saving.value = true;
    try {
        const payload = questions.value.map((q) => {
            const row = {
                question_text: String(q.question_text || '').trim(),
                type: q.type,
                points: Number(q.points) || 1,
                reference_answer: String(q.reference_answer || '').trim() || null,
            };
            // Only send an id for rows the server already knows about.
            if (q.id > 0) row.id = q.id;

            row.options = isEssay(q) ? [] : q.options.map((o) => {
                const opt = {
                    option_text: String(o.option_text || '').trim(),
                    is_correct: Boolean(o.is_correct),
                };
                if (o.id > 0) opt.id = o.id;
                return opt;
            });

            return row;
        });

        await teacher.syncQuestions(examId, payload);
        toast.success(t('examQuestions.savedToast'));
        await load();
    } catch (e) {
        toast.error(e.message);
    } finally {
        saving.value = false;
    }
}

// ---- Templates -------------------------------------------------------------
const templates = ref([]);
const templatesError = ref('');
const selectedTemplate = ref('');
const applyBusy = ref(false);

const templateOptions = computed(() => templates.value.map((tpl) => ({
    value: tpl.id,
    label: templateLabel(tpl),
})));

function templateLabel(tpl) {
    // Seeded templates are translated through their preset key so an Arabic
    // session reads them in Arabic; a teacher's own template is shown verbatim.
    if (tpl.is_system && tpl.preset_key && te(`examTemplates.presets.${tpl.preset_key}`)) {
        return t(`examTemplates.presets.${tpl.preset_key}`);
    }
    return tpl.name;
}
function templateDescription(tpl) {
    if (tpl.is_system && tpl.preset_key && te(`examTemplates.presetDescriptions.${tpl.preset_key}`)) {
        return t(`examTemplates.presetDescriptions.${tpl.preset_key}`);
    }
    return tpl.description || '';
}

async function loadTemplates() {
    templatesError.value = '';
    try {
        const res = await teacher.examTemplates();
        // The catalog is paginated: rows may arrive as a bare array or nested
        // under data.data, so unwrap defensively instead of assuming a shape.
        const payload = res?.data ?? res;
        templates.value = Array.isArray(payload) ? payload : (payload?.data || []);
    } catch (e) {
        // Never present a silently-empty picker. Surface the real reason
        // (403 / 500 / missing migration) so the cause is visible instead of
        // looking like "there are just no templates".
        templates.value = [];
        templatesError.value = e?.message || '';
    }
}

async function applyTemplate() {
    if (!selectedTemplate.value) return;
    applyBusy.value = true;
    try {
        const res = await teacher.applyExamTemplate(examId, Number(selectedTemplate.value));
        const created = res?.created_count ?? 0;
        toast.success(t('examQuestions.templateAppliedToast', { n: created }));
        selectedTemplate.value = '';
        await load();
    } catch (e) {
        toast.error(e.message);
    } finally {
        applyBusy.value = false;
    }
}

const tplModal = ref(false);
const tplBusy = ref(false);
const tplForm = reactive({ name: '', description: '' });
function openTemplateModal() {
    tplForm.name = exam.value?.title ? `${exam.value.title}` : '';
    tplForm.description = '';
    tplModal.value = true;
}
async function saveAsTemplate() {
    tplBusy.value = true;
    try {
        await teacher.saveExamAsTemplate(examId, {
            name: tplForm.name.trim(),
            description: tplForm.description.trim() || null,
        });
        toast.success(t('examQuestions.templateSavedToast'));
        tplModal.value = false;
        await loadTemplates();
    } catch (e) {
        toast.error(e.message);
    } finally {
        tplBusy.value = false;
    }
}

async function deleteTemplate(tpl) {
    try {
        await teacher.deleteExamTemplate(tpl.id);
        toast.success(t('common.deleted'));
        await loadTemplates();
    } catch (e) {
        toast.error(e.message);
    }
}

// ---- Unsaved-changes guard --------------------------------------------------
function beforeUnload(e) {
    if (!dirty.value) return;
    e.preventDefault();
    e.returnValue = '';
}
onMounted(async () => {
    window.addEventListener('beforeunload', beforeUnload);
    await Promise.all([load(), loadTemplates()]);
});
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

function backToExam() {
    router.push(`/teacher/exams/${examId}`);
}

const isPublished = computed(() => exam.value?.status === 'published');
</script>

<template>
    <div class="space-y-5 pb-24">
        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <button class="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-terracotta-600" @click="backToExam">
                    <Icon name="chevronRight" :size="15" class="rotate-180 rtl:rotate-0" />
                    {{ $t('examQuestions.backToExam') }}
                </button>
                <h1 class="truncate text-2xl font-bold text-ink-900" dir="auto">{{ exam?.title || $t('examQuestions.title') }}</h1>
                <p class="text-sm text-ink-500">{{ $t('examQuestions.subtitle') }}</p>
            </div>

            <div class="flex items-center gap-2">
                <AppBadge v-if="dirty" tone="warning">{{ $t('examQuestions.unsaved') }}</AppBadge>
                <AppBadge v-else tone="success">{{ $t('examQuestions.allSaved') }}</AppBadge>
                <AppButton :loading="saving" :disabled="!dirty || isPublished" @click="saveAll">
                    <Icon name="check" :size="15" />
                    {{ $t('examQuestions.saveAll') }}
                </AppButton>
            </div>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="loadError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ loadError }}</div>

        <template v-else>
            <div v-if="isPublished" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ $t('examQuestions.publishedLocked') }}
            </div>

            <!-- Readiness -->
            <div class="rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-4 text-sm">
                        <span class="font-semibold text-ink-800">{{ $t('examQuestions.readiness') }}</span>
                        <span class="text-ink-600">{{ $t('examQuestions.readyCount', { done: completeCount, total: questions.length }) }}</span>
                        <span class="text-ink-600">{{ $t('examQuestions.totalMarks', { n: totalPoints }) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <AppButton size="sm" variant="ghost" @click="expandAll">{{ $t('examQuestions.expandAll') }}</AppButton>
                        <AppButton size="sm" variant="ghost" @click="collapseAll">{{ $t('examQuestions.collapseAll') }}</AppButton>
                    </div>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-ink-100" role="progressbar" :aria-valuenow="progressPct" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: progressPct + '%' }" />
                </div>
            </div>

            <!-- Templates -->
            <div class="rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center gap-2">
                    <Icon name="layers" :size="16" class="text-terracotta-600" />
                    <h2 class="text-sm font-semibold text-ink-800">{{ $t('examTemplates.title') }}</h2>
                </div>

                <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                    <AppSelect
                        v-model="selectedTemplate"
                        :label="$t('examTemplates.choose')"
                        :options="templateOptions"
                        id="template-select"
                        :placeholder="$t('examTemplates.selectPlaceholder')"
                        class="flex-1"
                    />
                    <AppButton :loading="applyBusy" :disabled="!selectedTemplate || isPublished" @click="applyTemplate">
                        {{ $t('examTemplates.apply') }}
                    </AppButton>
                    <AppButton variant="secondary" :disabled="!questions.length" @click="openTemplateModal">
                        {{ $t('examTemplates.saveAsTemplate') }}
                    </AppButton>
                </div>

                <p v-if="isPublished" class="mt-2 text-xs font-medium text-amber-700" dir="auto">
                    {{ $t('examTemplates.publishedLocked') }}
                </p>

                <p v-if="templatesError" class="mt-2 text-xs font-medium text-rose-600" dir="auto">
                    {{ $t('examTemplates.loadError', { message: templatesError }) }}
                </p>
                <p v-else-if="!templates.length" class="mt-2 text-xs text-ink-400" dir="auto">
                    {{ $t('examTemplates.empty') }}
                </p>

                <p v-if="selectedTemplate" class="mt-3 text-sm text-ink-500" dir="auto">
                    {{ templateDescription(templates.find((tp) => tp.id === Number(selectedTemplate))) }}
                </p>
                <p class="mt-2 text-xs text-ink-400">{{ $t('examTemplates.appendHint') }}</p>
            </div>

            <!-- Question list -->
            <div v-if="!questions.length" class="rounded-xl border border-dashed border-ink-200 bg-white p-8 text-center">
                <Icon name="clipboard" :size="32" class="mx-auto mb-3 text-ink-300" />
                <p class="mb-1 font-medium text-ink-700">{{ $t('examQuestions.emptyTitle') }}</p>
                <p class="mb-4 text-sm text-ink-500">{{ $t('examQuestions.emptyMessage') }}</p>
                <div class="flex flex-wrap justify-center gap-2">
                    <AppButton @click="addQuestion('single_choice')" :disabled="isPublished">{{ $t('examQuestions.addMcq') }}</AppButton>
                    <AppButton variant="secondary" @click="addQuestion('essay')" :disabled="isPublished">{{ $t('examQuestions.addEssay') }}</AppButton>
                </div>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="(q, index) in questions"
                    :key="q._key"
                    class="overflow-hidden rounded-xl border bg-white shadow-sm transition"
                    :class="issuesFor(q).length ? 'border-amber-200' : 'border-emerald-200'"
                >
                    <button class="flex w-full items-center gap-3 px-4 py-3 text-start" @click="toggle(q)" :aria-expanded="expanded.has(q._key)">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                            :class="issuesFor(q).length ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
                        >{{ index + 1 }}</span>

                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink-800" dir="auto">
                            {{ q.question_text?.trim() || $t('examQuestions.untitled') }}
                        </span>

                        <AppBadge :tone="isEssay(q) ? 'purple' : 'info'">
                            {{ typeOptions.find((o) => o.value === q.type)?.label }}
                        </AppBadge>
                        <span class="shrink-0 text-xs text-ink-500">{{ $t('examQuestions.marksShort', { n: q.points }) }}</span>
                        <Icon name="chevronRight" :size="16" class="shrink-0 text-ink-400 transition-transform" :class="expanded.has(q._key) ? 'rotate-90 rtl:-rotate-90' : 'rtl:rotate-180'" />
                    </button>

                    <div v-if="expanded.has(q._key)" class="space-y-4 border-t border-ink-100 p-4">
                        <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                            <AppSelect
                                :model-value="q.type"
                                :label="$t('exams.questionTypeLabel')"
                                :options="typeOptions"
                                :id="`q-type-${q._key}`"
                                :disabled="isPublished"
                                @update:model-value="changeType(q, $event)"
                            />
                            <AppInput
                                v-model="q.points"
                                type="number"
                                :label="$t('exams.points')"
                                :id="`q-points-${q._key}`"
                                :disabled="isPublished"
                                class="w-full sm:w-28"
                                @update:model-value="markDirty"
                            />
                        </div>

                        <AppTextarea
                            v-model="q.question_text"
                            :label="$t('exams.questionText')"
                            :rows="3"
                            :id="`q-text-${q._key}`"
                            :placeholder="$t('examQuestions.questionPlaceholder')"
                            :disabled="isPublished"
                            @update:model-value="markDirty"
                        />

                        <div class="rounded-lg border border-ink-100 bg-ink-50/50 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-ink-800">{{ $t('examQuestions.questionImage') }}</p>
                                    <p class="text-xs text-ink-500">{{ $t('examQuestions.questionImageHint') }}</p>
                                </div>
                                <label v-if="!isPublished" class="cursor-pointer rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm font-medium text-ink-700 hover:bg-ink-50">
                                    {{ q.image_url ? $t('examQuestions.replaceImage') : $t('examQuestions.uploadImage') }}
                                    <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only" @change="uploadImage(q, $event)" />
                                </label>
                            </div>
                            <div v-if="q.image_url" class="relative mt-3 max-w-xl">
                                <img :src="q.image_url" :alt="$t('examQuestions.questionImage')" class="max-h-72 rounded-lg border border-ink-200 object-contain bg-white" />
                                <button v-if="!isPublished" type="button" class="absolute end-2 top-2 rounded bg-white px-2 py-1 text-xs font-medium text-rose-600 shadow hover:bg-rose-50" @click="removeImage(q)">{{ $t('common.remove') }}</button>
                            </div>
                        </div>

                        <!-- MCQ options -->
                        <div v-if="!isEssay(q)">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-medium text-ink-800">{{ $t('examQuestions.options') }}</span>
                                <span class="text-xs text-ink-400">{{ $t('examQuestions.markCorrectHint') }}</span>
                            </div>

                            <div class="space-y-2">
                                <div v-for="(option, oi) in q.options" :key="option.id ?? `o-${oi}`" class="flex items-center gap-2">
                                    <input
                                        :type="q.type === 'multiple_choice' ? 'checkbox' : 'radio'"
                                        :name="`correct-${q._key}`"
                                        class="h-4 w-4 shrink-0 border-ink-300 text-emerald-600 focus:ring-emerald-500"
                                        :checked="option.is_correct"
                                        :disabled="isPublished"
                                        :aria-label="$t('examQuestions.markCorrect')"
                                        @change="markCorrect(q, option, $event.target.checked)"
                                    />
                                    <input
                                        v-model="option.option_text"
                                        type="text"
                                        class="w-full rounded-lg border border-ink-200 px-3 py-2 text-sm focus:border-terracotta-500 focus:outline-none focus:ring-1 focus:ring-terracotta-500"
                                        :placeholder="`${$t('examQuestions.optionN', { n: oi + 1 })}`"
                                        :disabled="isPublished"
                                        @input="markDirty"
                                    />
                                    <button
                                        v-if="!isPublished"
                                        class="shrink-0 rounded-lg p-2 text-ink-400 hover:bg-rose-50 hover:text-rose-600"
                                        :aria-label="$t('common.delete')"
                                        @click="removeOption(q, option)"
                                    >
                                        <Icon name="x" :size="15" />
                                    </button>
                                </div>
                            </div>

                            <AppButton v-if="!isPublished" size="sm" variant="ghost" class="mt-2" @click="addOption(q)">
                                <Icon name="plus" :size="14" />
                                {{ $t('examQuestions.addOption') }}
                            </AppButton>
                        </div>

                        <!-- Essay reference answer -->
                        <AppTextarea
                            v-else
                            v-model="q.reference_answer"
                            :label="$t('examQuestions.referenceAnswer')"
                            :hint="$t('examQuestions.referenceAnswerHint')"
                            :rows="3"
                            :id="`q-ref-${q._key}`"
                            :placeholder="$t('examQuestions.referencePlaceholder')"
                            :disabled="isPublished"
                            @update:model-value="markDirty"
                        />

                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-ink-50 pt-3">
                            <span v-if="issuesFor(q).length" class="text-xs text-amber-700">
                                {{ $t('examQuestions.missing') }}:
                                {{ issuesFor(q).map((k) => $t(`examQuestions.issue.${k}`)).join(' · ') }}
                            </span>
                            <span v-else class="text-xs text-emerald-600">{{ $t('examQuestions.questionReady') }}</span>

                            <AppButton v-if="!isPublished" size="sm" variant="danger" @click="confirmDeleteQuestion(q)">
                                <Icon name="x" :size="14" />
                                {{ $t('examQuestions.deleteQuestion') }}
                            </AppButton>
                        </div>
                    </div>
                </div>

                <div v-if="!isPublished" class="flex flex-wrap justify-center gap-2 pt-2">
                    <AppButton variant="secondary" @click="addQuestion('single_choice')">
                        <Icon name="plus" :size="15" />
                        {{ $t('examQuestions.addMcq') }}
                    </AppButton>
                    <AppButton variant="secondary" @click="addQuestion('essay')">
                        <Icon name="plus" :size="15" />
                        {{ $t('examQuestions.addEssay') }}
                    </AppButton>
                </div>
            </div>
        </template>
    </div>

    <!-- Save as template -->
    <AppModal :open="tplModal" :title="$t('examTemplates.saveAsTemplate')" size="md" @close="tplModal = false">
        <div class="space-y-3">
            <p class="text-sm text-ink-500">{{ $t('examTemplates.saveAsHint') }}</p>
            <AppInput v-model="tplForm.name" :label="$t('examTemplates.name')" :placeholder="$t('examTemplates.namePlaceholder')" required />
            <AppTextarea v-model="tplForm.description" :label="$t('examTemplates.description')" :rows="2" />
        </div>
        <template #footer>
            <AppButton variant="ghost" @click="tplModal = false">{{ $t('common.cancel') }}</AppButton>
            <AppButton :loading="tplBusy" :disabled="!tplForm.name.trim()" @click="saveAsTemplate">{{ $t('common.save') }}</AppButton>
        </template>
    </AppModal>

    <ConfirmDialog
        :open="Boolean(deleteTarget)"
        :title="$t('examQuestions.deleteQuestionTitle')"
        :message="$t('examQuestions.deleteQuestionMessage')"
        :confirm-text="$t('common.delete')"
        :loading="deleteBusy"
        @close="deleteTarget = null"
        @confirm="runDeleteQuestion"
    />
</template>
