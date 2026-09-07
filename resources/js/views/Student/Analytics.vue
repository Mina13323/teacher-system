<script setup>
import { onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { student } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import StatCard from '@/components/ui/StatCard.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Icon from '@/components/ui/Icon.vue';

const { loading, error, data, run } = useAsync(() => student.analytics());
onMounted(() => run());
</script>

<template>
    <div class="space-y-6">
        <h1 class="text-2xl font-bold text-ink-900">My analytics</h1>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Courses" :value="data.enrollments_count" icon="book" tone="terracotta" />
                <StatCard label="Lessons completed" :value="data.lessons_completed_count" icon="check" tone="emerald" />
                <StatCard label="Exams taken" :value="data.attempts_count" icon="clipboard" tone="sky" />
                <StatCard label="Average score" :value="data.average_score ?? '—'" icon="chart" tone="amber" />
            </div>

            <AppCard title="Exam history">
                <EmptyState v-if="!data.history?.length" icon="clipboard" title="No exam history yet" message="Your exam results will appear here." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-ink-100 text-left text-xs uppercase tracking-wide text-ink-400">
                                <th class="px-3 py-2">Exam</th>
                                <th class="px-3 py-2">Score</th>
                                <th class="px-3 py-2">Result</th>
                                <th class="px-3 py-2">Date</th>
                            </tr>
                        </thead>
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
                <div class="space-y-2">
                    <div v-for="r in data.competition_results" :key="r.competition_id + r.rank" class="flex items-center gap-3 rounded-lg px-3 py-2.5 bg-ink-50/50">
                        <Icon name="trophy" :size="18" class="text-amber-500" />
                        <span class="flex-1 font-medium text-ink-800">{{ r.competition_title }}</span>
                        <span class="text-sm text-ink-600">#{{ r.rank }}</span>
                        <span class="text-xs text-ink-400">{{ r.percentage }}%</span>
                    </div>
                </div>
            </AppCard>
        </template>
    </div>
</template>
