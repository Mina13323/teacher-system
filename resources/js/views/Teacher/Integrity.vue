<script setup>
import { ref, onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { teacher, toList } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Icon from '@/components/ui/Icon.vue';

const { loading, error, data, run } = useAsync(() => teacher.analyticsOverview());
const flagged = ref([]);
const collecting = ref(false);

async function collect() {
    collecting.value = true;
    try {
        const courses = toList(await teacher.courses({ per_page: 50 })).items;
        const rows = [];
        for (const c of courses.slice(0, 12)) {
            const exams = toList(await teacher.exams(c.id, { per_page: 50 })).items;
            for (const e of exams.slice(0, 12)) {
                const attempts = toList(await teacher.examAttempts(e.id, { per_page: 20 })).items;
                const flaggedHere = attempts.filter((a) => a.integrity_status && ['flagged', 'monitoring', 'reviewed', 'cleared'].includes(a.integrity_status));
                flaggedHere.forEach((a) => rows.push({ ...a, exam_title: e.title, course_title: c.title }));
            }
        }
        flagged.value = rows;
    } catch {
        flagged.value = [];
    } finally {
        collecting.value = false;
    }
}

function tone(status) {
    return { flagged: 'danger', monitoring: 'warning', reviewed: 'info', cleared: 'success' }[status] || 'neutral';
}

onMounted(async () => {
    await run();
    await collect();
});
</script>

<template>
    <div class="space-y-6">
        <h1 class="text-2xl font-bold text-ink-900">{{ $t('integrity.reviewTitle') }}</h1>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <StatCard :label="$t('integrity.flaggedStat')" :value="data.flagged_integrity_count" icon="shield" tone="danger" />

            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-ink-900">{{ $t('integrity.needsAttention') }}</h2>
                <AppButton variant="outline" :loading="collecting" @click="collect">{{ $t('common.refresh') }}</AppButton>
            </div>

            <LoadingSpinner v-if="collecting" />
            <EmptyState v-else-if="!flagged.length" icon="shield" :title="$t('integrity.noFlaggedTitle')" :message="$t('integrity.noFlaggedMessage')" />
            <div v-else class="space-y-3">
                <div v-for="a in flagged" :key="a.id" class="flex items-center gap-3 rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-ink-100 text-ink-600"><Icon name="shield" :size="20" /></div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-ink-800" dir="auto">{{ a.student?.name }} · {{ a.exam_title }}</p>
                        <p class="text-xs text-ink-400" dir="auto">{{ a.course_title }} · {{ $t('common.attemptN', { n: a.attempt_number }) }} · {{ a.percentage !== null ? a.percentage + '%' : '—' }}</p>
                    </div>
                    <AppBadge :tone="tone(a.integrity_status)">{{ $t(`status.${a.integrity_status}`, a.integrity_status) }}</AppBadge>
                    <router-link :to="`/teacher/integrity/attempts/${a.id}`"><AppButton size="sm">{{ $t('integrity.review') }}</AppButton></router-link>
                </div>
            </div>
        </template>
    </div>
</template>
