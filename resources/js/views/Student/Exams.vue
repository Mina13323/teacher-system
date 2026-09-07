<script setup>
import { ref, onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { student, toList } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Icon from '@/components/ui/Icon.vue';

const { loading, error, data, run } = useAsync(async (page = 1) => {
    const res = await student.exams({ per_page: 10, page });
    return toList(res);
});

function load(page) { return run(page); }
onMounted(() => run(1));
</script>

<template>
    <div class="space-y-6">
        <h1 class="text-2xl font-bold text-ink-900">Exams</h1>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>
        <EmptyState v-else-if="!data.items.length" icon="clipboard" title="No exams available" message="Exams from your courses will appear here once published." />
        <div v-else class="space-y-3">
            <router-link v-for="e in data.items" :key="e.id" :to="`/student/exams/${e.id}`" class="group block">
                <div class="flex items-center gap-4 rounded-xl border border-ink-100 bg-white p-5 shadow-sm transition group-hover:shadow-md">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600">
                        <Icon name="clipboard" :size="24" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="font-semibold text-ink-900 group-hover:text-terracotta-700">{{ e.title }}</h3>
                        <p class="truncate text-sm text-ink-500">{{ e.course?.title }}</p>
                    </div>
                    <div class="hidden items-center gap-3 text-xs text-ink-400 sm:flex">
                        <span>{{ e.duration_minutes }} min</span>
                        <span class="h-1 w-1 rounded-full bg-ink-300" />
                        <span>{{ e.questions_count }} questions</span>
                        <span class="h-1 w-1 rounded-full bg-ink-300" />
                        <span>{{ e.max_attempts }} attempts</span>
                    </div>
                    <AppBadge tone="primary">Take exam</AppBadge>
                </div>
            </router-link>
            <Pagination v-if="data?.meta" :meta="data.meta" @change="load" />
        </div>
    </div>
</template>
