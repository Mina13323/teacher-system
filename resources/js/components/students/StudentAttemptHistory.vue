<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { teacher } from '@/api';
import { formatDate } from '@/utils/format';
import { groupAttemptsByExam, scoreText } from '@/utils/studentAttempts';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import Pagination from '@/components/ui/Pagination.vue';

/**
 * Student -> exam -> attempts on the staff student page: server-paginated,
 * grouped by exam, each attempt linked to its detail view and, while essays
 * wait for grades, to the grading workspace.
 */
const props = defineProps({
    studentId: { type: [Number, String], required: true },
});

const { t } = useI18n();
const rows = ref([]);
const meta = ref(null);
const loading = ref(true);
const error = ref(null);
let seq = 0;

const groups = computed(() => groupAttemptsByExam(rows.value));

async function load(page = 1) {
    const mine = ++seq;
    loading.value = true;
    error.value = null;
    try {
        const res = await teacher.studentAttempts(props.studentId, { page, per_page: 20 });
        if (mine !== seq) return;
        rows.value = res?.attempts || [];
        meta.value = res?.meta || null;
    } catch (e) {
        if (mine === seq) error.value = e;
    } finally {
        if (mine === seq) loading.value = false;
    }
}

function statusTone(a) {
    if (a.grades_published) return 'success';
    return { grading: 'warning', submitted: 'info', in_progress: 'info', expired: 'danger' }[a.status] || 'neutral';
}

function statusLabel(a) {
    if (a.grades_published) return t('exams.statusPublishedLabel');
    return {
        grading: t('exams.statusGradingLabel'),
        submitted: t('exams.statusSubmittedLabel'),
        in_progress: t('exams.statusInProgressLabel'),
        expired: t('exams.statusExpiredLabel'),
    }[a.status] || a.status;
}

watch(() => props.studentId, () => load(1));
onMounted(() => load(1));
</script>

<template>
    <AppCard :title="$t('students.attemptHistory')">
        <LoadingSpinner v-if="loading && !rows.length" />
        <p v-else-if="error" class="text-sm text-rose-700" role="alert">{{ error.message }}</p>
        <EmptyState v-else-if="!rows.length" icon="clipboard" :title="$t('students.noAttemptsTitle')" :message="$t('students.noAttemptsMessage')" />
        <div v-else class="space-y-4">
            <section v-for="g in groups" :key="g.exam_id" class="rounded-lg border border-ink-100">
                <h3 class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 bg-ink-50/50 px-3 py-2">
                    <router-link :to="`/teacher/exams/${g.exam_id}`" class="min-w-0 break-words text-sm font-semibold text-terracotta-700 hover:underline" dir="auto">{{ g.exam_title }}</router-link>
                    <span class="text-xs text-ink-500">{{ $t('students.attemptsCount', { n: g.attempts.length }, g.attempts.length) }}</span>
                </h3>
                <ul class="divide-y divide-ink-100">
                    <li v-for="a in g.attempts" :key="a.attempt_id" class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0 text-sm">
                            <span class="font-medium text-ink-800">{{ $t('grading.attemptN', { n: a.attempt_number }) }}</span>
                            <span class="text-ink-500"> · {{ scoreText(a.score, a.percentage) }}</span>
                            <span v-if="a.submitted_at" class="text-xs text-ink-400"> · {{ formatDate(a.submitted_at) }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <AppBadge :tone="statusTone(a)">{{ statusLabel(a) }}</AppBadge>
                            <router-link
                                v-if="a.status === 'grading'"
                                :to="`/teacher/exams/${a.exam_id}/grading?attempt_id=${a.attempt_id}`"
                                class="inline-flex min-h-9 items-center rounded-lg bg-terracotta-600 px-3 text-xs font-semibold text-white hover:bg-terracotta-700"
                            >📝 {{ $t('grading.openWorkspace') }}</router-link>
                            <router-link
                                :to="`/teacher/integrity/attempts/${a.attempt_id}`"
                                class="inline-flex min-h-9 items-center rounded-lg border border-ink-200 px-3 text-xs font-medium text-ink-700 hover:bg-ink-50"
                            >{{ $t('grading.viewAttempt') }}</router-link>
                        </div>
                    </li>
                </ul>
            </section>
            <Pagination v-if="meta && meta.last_page > 1" :meta="meta" @change="load" />
        </div>
    </AppCard>
</template>
