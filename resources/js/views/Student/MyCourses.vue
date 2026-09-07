<script setup>
import { onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { student, toList } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Icon from '@/components/ui/Icon.vue';

const { loading, error, data, run } = useAsync(async () => {
    const res = await student.courses();
    return toList(res).items;
});
onMounted(() => run());
</script>

<template>
    <div class="space-y-6">
        <h1 class="text-2xl font-bold text-ink-900">My courses</h1>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>
        <EmptyState v-else-if="!data.length" icon="book" title="No courses yet" message="You aren't enrolled in any courses yet. Browse the catalog to get started.">
            <router-link to="/courses"><AppButton>Browse courses</AppButton></router-link>
        </EmptyState>
        <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <router-link v-for="c in data" :key="c.id" :to="`/student/courses/${c.id}`" class="group">
                <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm transition group-hover:shadow-md">
                    <div class="flex h-32 items-center justify-center bg-gradient-to-br from-ink-800 to-ink-900 text-white">
                        <Icon name="book" :size="36" class="text-terracotta-400" />
                    </div>
                    <div class="p-5">
                        <h3 class="font-semibold text-ink-900 group-hover:text-terracotta-700">{{ c.title }}</h3>
                        <p class="mt-1 line-clamp-2 text-sm text-ink-500">{{ c.description }}</p>
                        <div class="mt-3 flex items-center justify-between text-xs text-ink-400">
                            <span>{{ c.units_count }} units · {{ c.lessons_count }} lessons</span>
                            <span>{{ c.progress }}%</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full bg-terracotta-500 transition-all" :style="{ width: `${c.progress}%` }" />
                        </div>
                    </div>
                </div>
            </router-link>
        </div>
    </div>
</template>
