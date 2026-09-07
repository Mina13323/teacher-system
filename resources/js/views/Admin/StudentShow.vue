<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { admin } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import StatCard from '@/components/ui/StatCard.vue';

const route = useRoute();
const toast = useToast();
const loading = ref(true);
const error = ref('');
const student = ref(null);
const analytics = ref(null);

async function load() {
    loading.value = true;
    try {
        const [s, a] = await Promise.all([
            admin.student(route.params.id),
            admin.studentAnalytics(route.params.id),
        ]);
        student.value = s;
        analytics.value = a;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link to="/admin/students" class="text-sm font-medium text-terracotta-600 hover:underline">← Back to students</router-link>
            <h1 class="mt-2 text-2xl font-bold text-ink-900">{{ student?.name || 'Student' }}</h1>
            <p class="text-ink-500">{{ student?.email }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-4">
                <StatCard label="Status" :value="student.is_active ? 'Active' : 'Inactive'" icon="user" :tone="student.is_active ? 'emerald' : 'ink'" />
                <StatCard label="Profile" :value="student.profile_completed ? 'Complete' : 'Incomplete'" icon="check" :tone="student.profile_completed ? 'success' : 'warning'" />
                <StatCard label="Enrollments" :value="analytics?.courses?.length ?? 0" icon="layers" tone="sky" />
                <StatCard label="Avg score" :value="analytics?.average_score ?? '—'" icon="chart" tone="terracotta" />
            </div>

            <AppCard v-if="analytics?.courses?.length" title="Enrollments">
                <div class="space-y-2">
                    <div v-for="(e, idx) in analytics.courses" :key="idx" class="flex items-center gap-3 rounded-lg bg-ink-50 px-4 py-2.5 text-sm">
                        <span class="flex-1 font-medium text-ink-800">{{ e.title }}</span>
                        <span class="text-xs text-ink-400">enrolled {{ e.enrolled_at ? new Date(e.enrolled_at).toLocaleDateString() : '—' }}</span>
                    </div>
                </div>
            </AppCard>

            <AppCard title="Exam history">
                <EmptyState v-if="!analytics?.history?.length" icon="clipboard" title="No exam history" message="This student hasn't submitted any exams yet." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-ink-100 text-left text-xs uppercase tracking-wide text-ink-400"><th class="px-3 py-2">Exam</th><th class="px-3 py-2">Score</th><th class="px-3 py-2">Result</th><th class="px-3 py-2">Date</th></tr></thead>
                        <tbody>
                            <tr v-for="h in analytics.history" :key="h.attempt_id" class="border-b border-ink-50">
                                <td class="px-3 py-2.5 font-medium text-ink-800">{{ h.exam_title }}</td>
                                <td class="px-3 py-2.5 text-ink-600">{{ h.percentage }}%</td>
                                <td class="px-3 py-2.5"><AppBadge :tone="h.passed === true ? 'success' : h.passed === false ? 'danger' : 'neutral'">{{ h.passed === true ? 'Passed' : h.passed === false ? 'Not passed' : '—' }}</AppBadge></td>
                                <td class="px-3 py-2.5 text-ink-500">{{ h.submitted_at ? new Date(h.submitted_at).toLocaleDateString() : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard v-if="analytics?.competition_results?.length" title="Competition results">
                <div v-for="r in analytics.competition_results" :key="r.competition_id + r.rank" class="flex items-center gap-3 rounded-lg bg-ink-50 px-4 py-2.5 text-sm">
                    <span class="flex-1 font-medium text-ink-800">{{ r.competition_title }}</span>
                    <span class="text-ink-600">#{{ r.rank }}</span>
                    <AppBadge :tone="r.qualified ? 'success' : 'neutral'">{{ r.qualified ? 'Qualified' : '—' }}</AppBadge>
                </div>
            </AppCard>
        </template>
    </div>
</template>
