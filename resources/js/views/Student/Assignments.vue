<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { student, toList } from '@/api';
import { useToast } from '@/composables/toast';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import Pagination from '@/components/ui/Pagination.vue';

/**
 * Student assignments (P2): one screen for the whole loop —
 * see what is due, open it, submit text and/or a file, see the grade and the
 * teacher's feedback. Every row shows its own state; no dead ends.
 */
const { t } = useI18n();
const toast = useToast();

const loading = ref(true);
const items = ref([]);
// Homework: questions like an exam, open between two set times, no proctoring.
const homework = ref([]);
const homeworkMeta = ref(null);
const expanded = ref(null);
const detail = ref(null); // { assignment, my_submission }
const detailBusy = ref(false);

const answerText = ref('');
const file = ref(null);
const submitBusy = ref(false);

function fmtDate(value) {
    if (!value) return '—';
    const dt = new Date(value);
    return Number.isNaN(dt.getTime()) ? value : dt.toLocaleString();
}

function homeworkState(h) {
    const now = Date.now();
    if (h.starts_at && new Date(h.starts_at).getTime() > now) return 'upcoming';
    if (h.ends_at && new Date(h.ends_at).getTime() <= now) return 'closed';
    return 'open';
}
const homeworkTone = { open: 'success', upcoming: 'neutral', closed: 'warning' };

async function loadHomework(page = 1) {
    const res = toList(await student.exams({ per_page: 10, page, type: 'homework' }));
    homework.value = res.items;
    homeworkMeta.value = res.meta;
}

async function load() {
    loading.value = true;
    try {
        const [res] = await Promise.all([
            student.assignments({ per_page: 30 }).then(toList),
            loadHomework(1),
        ]);
        items.value = res.items;
    } catch (e) {
        toast.error(e.message);
    } finally {
        loading.value = false;
    }
}

async function toggle(id) {
    if (expanded.value === id) {
        expanded.value = null;
        detail.value = null;
        return;
    }
    expanded.value = id;
    detailBusy.value = true;
    try {
        detail.value = await student.assignment(id);
        answerText.value = detail.value.my_submission?.answer_text || '';
    } catch (e) {
        toast.error(e.message);
    } finally {
        detailBusy.value = false;
    }
}

function onFileChange(e) {
    file.value = e.target.files?.[0] || null;
}

async function submit() {
    if (!answerText.value.trim() && !file.value) {
        toast.error(t('assignments.needWork'));
        return;
    }
    submitBusy.value = true;
    try {
        const payload = new FormData();
        if (answerText.value.trim()) payload.append('answer_text', answerText.value);
        if (file.value) payload.append('file', file.value);
        await student.submitAssignment(expanded.value, payload);
        toast.success(t('assignments.submitted'));
        detail.value = await student.assignment(expanded.value);
        file.value = null;
    } catch (e) {
        toast.error(e.message);
    } finally {
        submitBusy.value = false;
    }
}

async function downloadFileOf(submission) {
    try {
        await student.downloadSubmissionFile(submission.id, submission.file?.name);
    } catch (e) {
        toast.error(e.message);
    }
}

const canSubmit = computed(() => {
    const sub = detail.value?.my_submission;
    return !sub || sub.status !== 'graded';
});

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold text-ink-900">{{ $t('nav.assignments') }}</h1>

        <LoadingSpinner v-if="loading" />
        <EmptyState v-else-if="!items.length && !homework.length" icon="clipboard" :title="$t('assignments.emptyTitle')" :message="$t('assignments.emptyMessage')" />

        <div v-if="!loading && homework.length" class="space-y-3">
            <router-link v-for="h in homework" :key="h.id" :to="`/student/exams/${h.id}`" class="group block">
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-ink-100 bg-white px-4 py-3.5 shadow-sm transition group-hover:shadow-md">
                    <Icon name="clipboard" :size="20" class="text-terracotta-500" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-ink-800 group-hover:text-terracotta-700" dir="auto">{{ h.title }}</p>
                        <p class="text-xs text-ink-400">
                            {{ $t('homework.window', { from: fmtDate(h.starts_at), to: fmtDate(h.ends_at) }) }}
                            · {{ $t('common.questionsCount', { n: h.questions_count }) }}
                            <span v-if="h.course?.title" dir="auto"> · {{ h.course.title }}</span>
                        </p>
                    </div>
                    <AppBadge v-if="h.attempts_count > 0" tone="success">{{ $t('homework.started') }}</AppBadge>
                    <AppBadge :tone="homeworkTone[homeworkState(h)]">{{ $t(`homework.state.${homeworkState(h)}`) }}</AppBadge>
                </div>
            </router-link>
            <Pagination v-if="homeworkMeta" :meta="homeworkMeta" @change="loadHomework" />
        </div>

        <div v-if="!loading && items.length" class="space-y-3">
            <p v-if="homework.length" class="text-sm font-semibold text-ink-700">{{ $t('homework.legacyTitle') }}</p>
            <div v-for="a in items" :key="a.id" class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                <button type="button" class="flex w-full flex-wrap items-center gap-3 px-4 py-3.5 text-start" @click="toggle(a.id)">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-ink-800" dir="auto">{{ a.title }}</p>
                        <p class="text-xs text-ink-400">
                            {{ $t('assignments.due') }}: {{ fmtDate(a.due_at) }}
                            <span v-if="a.is_past_due" class="text-rose-600 ms-2">{{ $t('assignments.pastDue') }}</span>
                            · {{ $t('assignments.points') }}: {{ a.points }}
                        </p>
                    </div>
                    <AppBadge v-if="a.my_submission_count > 0" tone="success">{{ $t('assignments.submittedBadge') }}</AppBadge>
                    <AppBadge v-else tone="warning">{{ $t('assignments.todoBadge') }}</AppBadge>
                    <span class="text-ink-400">{{ expanded === a.id ? '▲' : '▼' }}</span>
                </button>

                <div v-if="expanded === a.id" class="space-y-4 border-t border-ink-100 bg-ink-50/40 p-4">
                    <LoadingSpinner v-if="detailBusy" />
                    <template v-else-if="detail">
                        <p class="whitespace-pre-wrap text-sm text-ink-700" dir="auto">{{ detail.assignment.description }}</p>

                        <!-- My submission -->
                        <div v-if="detail.my_submission" class="rounded-lg border border-ink-100 bg-white p-3 text-sm">
                            <p class="font-semibold text-ink-800">{{ $t('assignments.mySubmission') }}</p>
                            <p class="mt-1 text-xs text-ink-400">
                                {{ $t('assignments.submittedAt') }}: {{ fmtDate(detail.my_submission.submitted_at) }}
                                <span v-if="detail.my_submission.is_late" class="text-rose-600 ms-2">{{ $t('assignments.lateBadge') }}</span>
                                · {{ detail.my_submission.status }}
                            </p>
                            <p v-if="detail.my_submission.answer_text" class="mt-2 whitespace-pre-wrap text-ink-700" dir="auto">{{ detail.my_submission.answer_text }}</p>
                            <button
                                v-if="detail.my_submission.file"
                                type="button"
                                class="mt-2 text-sm font-medium text-terracotta-600 hover:underline"
                                @click="downloadFileOf(detail.my_submission)"
                            >
                                📎 {{ detail.my_submission.file.name }}
                            </button>
                            <div v-if="detail.my_submission.status === 'graded'" class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                                <p class="font-semibold text-emerald-800">{{ $t('assignments.grade') }}: {{ detail.my_submission.score }} / {{ detail.assignment.points }}</p>
                                <p v-if="detail.my_submission.feedback" class="mt-1 text-emerald-900" dir="auto">{{ detail.my_submission.feedback }}</p>
                            </div>
                        </div>

                        <!-- Submit / resubmit -->
                        <div v-if="canSubmit" class="space-y-3">
                            <AppTextarea v-model="answerText" :rows="4" :label="$t('assignments.answerLabel')" id="assignment-answer" />
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-ink-800">{{ $t('assignments.fileLabel') }}</label>
                                <input type="file" class="text-sm" @change="onFileChange" />
                            </div>
                            <div class="flex justify-end">
                                <AppButton :loading="submitBusy" @click="submit">
                                    {{ detail.my_submission ? $t('assignments.resubmit') : $t('assignments.submit') }}
                                </AppButton>
                            </div>
                        </div>
                        <p v-else class="text-xs text-ink-400">{{ $t('assignments.gradedLocked') }}</p>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
