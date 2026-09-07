<script setup>
import { onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { admin } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import StatCard from '@/components/ui/StatCard.vue';
import AppCard from '@/components/ui/AppCard.vue';

const { loading, error, data, run } = useAsync(() => admin.dashboard());
onMounted(run);
</script>

<template>
    <div class="space-y-6">
        <h1 class="text-2xl font-bold text-ink-900">Admin dashboard</h1>
        <p class="text-ink-500">System-wide oversight of teachers, students, and content.</p>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Teachers" :value="data.teachers_count" icon="user" tone="terracotta" />
                <StatCard label="Students" :value="data.students_count" icon="users" tone="emerald" />
                <StatCard label="Courses" :value="data.courses_count" icon="book" tone="sky" />
                <StatCard label="Competitions" :value="data.competitions_count" icon="trophy" tone="amber" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Active enrollments" :value="data.active_enrollments_count" icon="layers" tone="ink" />
                <StatCard label="Submitted attempts" :value="data.submitted_attempts_count" icon="clipboard" tone="terracotta" />
                <StatCard label="Average score" :value="data.average_score ?? '—'" icon="chart" tone="emerald" />
                <StatCard label="Active attempts" :value="data.active_attempts_count" icon="play" tone="sky" />
            </div>

            <AppCard title="Integrity signal">
                <div class="flex items-center justify-between rounded-lg bg-rose-50 px-5 py-4">
                    <div>
                        <p class="font-semibold text-rose-800">Flagged integrity</p>
                        <p class="text-sm text-rose-600">{{ data.flagged_integrity_count }} attempts flagged or under monitoring.</p>
                    </div>
                    <span class="text-3xl font-bold text-rose-700">{{ data.flagged_integrity_count }}</span>
                </div>
            </AppCard>
        </template>
    </div>
</template>
