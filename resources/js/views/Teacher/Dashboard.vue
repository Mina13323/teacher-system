<script setup>
import { onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { teacher, toList } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import StatCard from '@/components/ui/StatCard.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Icon from '@/components/ui/Icon.vue';

const { loading, error, data, run } = useAsync(async () => {
    const res = await teacher.dashboard();
    return {
        ...res,
        recent_courses: toList(res.recent_courses).items,
    };
});
onMounted(() => run());

const shortcuts = [
    { to: '/teacher/students/new', label: 'Add Student', icon: 'users' },
    { to: '/teacher/assistants/new', label: 'Add Assistant', icon: 'user' },
    { to: '/teacher/courses/new', label: 'New Course', icon: 'book' },
    { to: '/teacher/competitions/new', label: 'New Competition', icon: 'trophy' },
];
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">Teacher dashboard</h1>
                <p class="text-sm text-ink-500">An overview of your educational operation.</p>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <router-link v-for="s in shortcuts" :key="s.to" :to="s.to">
                <AppButton variant="outline">
                    <Icon :name="s.icon" :size="16" /> {{ s.label }}
                </AppButton>
            </router-link>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Courses" :value="data.courses_count" icon="book" tone="terracotta" :hint="`${data.published_count} published`" />
                <StatCard label="Enrollments" :value="data.total_enrollments" icon="users" tone="emerald" />
                <StatCard label="Lessons" :value="data.total_lessons" icon="layers" tone="sky" :hint="`${data.total_units} units`" />
                <StatCard label="Drafts" :value="data.draft_count" icon="clipboard" tone="amber" />
            </div>

            <AppCard title="Recent courses">
                <template v-if="data.recent_courses.length">
                    <router-link
                        v-for="c in data.recent_courses"
                        :key="c.id"
                        :to="`/teacher/courses/${c.id}`"
                        class="flex items-center gap-4 rounded-xl px-2 py-3 transition hover:bg-parchment-50"
                    >
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-ink-100 text-ink-600"><Icon name="book" :size="20" /></div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink-800">{{ c.title }}</p>
                            <p class="text-xs text-ink-400">{{ c.enrollments_count }} enrollments · {{ c.lessons_count }} lessons</p>
                        </div>
                        <AppBadge :tone="c.status === 'published' ? 'success' : 'neutral'">{{ c.status }}</AppBadge>
                    </router-link>
                </template>
                <p v-else class="py-6 text-center text-sm text-ink-400">No courses yet. <router-link to="/teacher/courses/new" class="text-terracotta-600 hover:underline">Create your first course</router-link>.</p>
            </AppCard>
        </template>
    </div>
</template>
