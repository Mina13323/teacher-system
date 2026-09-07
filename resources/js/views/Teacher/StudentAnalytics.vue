<script setup>
import { onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useAsync } from '@/composables/useAsync';
import { teacher } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import StatCard from '@/components/ui/StatCard.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const route = useRoute();
const { loading, error, data, run } = useAsync(() => teacher.studentAnalytics(route.params.id));
onMounted(() => run());
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link to="/teacher/analytics" class="text-sm font-medium text-terracotta-600 hover:underline">← Back to analytics</router-link>
            <h1 class="mt-2 text-2xl font-bold text-ink-900">{{ data?.student?.name || 'Student analytics' }}</h1>
            <p class="text-ink-500">{{ data?.student?.email }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard label="Lessons completed" :value="data.lessons_completed_count" icon="check" tone="emerald" />
                <StatCard label="Exams taken" :value="data.attempts_count" icon="clipboard" tone="sky" />
                <StatCard label="Average score" :value="data.average_score ?? '—'" icon="chart" tone="amber" />
            </div>

            <AppCard title="Exam history">
                <EmptyState v-if="!data.history?.length" icon="clipboard" title="No exam history" message="This student hasn't submitted any exams yet." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-ink-100 text-left text-xs uppercase tracking-wide text-ink-400"><th class="px-3 py-2">Exam</th><th class="px-3 py-2">Score</th><th class="px-3 py-2">Result</th><th class="px-3 py-2">Date</th></tr></thead>
                        <tbody>
                            <tr v-for="h in data.history" :key="h.attempt_id" class="border-b border-ink-50">
                                <td class="px-3 py-2.5 font-medium text-ink-800">{{ h.exam_title }}</td>
                                <td class="px-3 py-2.5 text-ink-600">{{ h.percentage }}%</td>
                                <td class="px-3 py-2.5"><AppBadge :tone="h.passed === true ? 'success' : h.passed === false ? 'danger' : 'neutral'">{{ h.passed === true ? 'Passed' : h.passed === false ? 'Not passed' : '—' }}</AppBadge></td>
                                <td class="px-3 py-2.5 text-ink-500">{{ h.submitted_at ? new Date(h.submitted_at).toLocaleDateString() : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard v-if="data.competition_results?.length" title="Competition results">
                <div v-for="r in data.competition_results" :key="r.competition_id + r.rank" class="flex items-center gap-3 rounded-lg bg-ink-50 px-4 py-2.5 text-sm">
                    <span class="flex-1 font-medium text-ink-800">{{ r.competition_title }}</span>
                    <span class="text-ink-600">#{{ r.rank }}</span>
                    <AppBadge :tone="r.qualified ? 'success' : 'neutral'">{{ r.qualified ? 'Qualified' : '—' }}</AppBadge>
                </div>
            </AppCard>
        </template>
    </div>
</template>
