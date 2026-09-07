<script setup>
import { onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { student, toList } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Icon from '@/components/ui/Icon.vue';

const { loading, error, data, run } = useAsync(async () => {
    const res = await student.dashboard();
    return {
        ...res,
        recently_accessed_lessons: toList(res.recently_accessed_lessons).items,
        courses: toList(res.courses).items,
    };
});
onMounted(() => run());

function fmtDate(iso) {
    return iso ? new Date(iso).toLocaleDateString() : '';
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('dashboard.welcomeBack') }}</h1>
                <p class="text-sm text-ink-500">{{ $t('dashboard.continueJourney') }}</p>
            </div>
            <router-link to="/student/courses"><AppButton>{{ $t('dashboard.browseCourses') }}</AppButton></router-link>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard :label="$t('dashboard.enrolledCourses')" :value="data.enrolled_courses_count" icon="book" tone="terracotta" />
                <StatCard :label="$t('dashboard.completedLessons')" :value="data.completed_lessons_count" icon="check" tone="emerald" />
                <StatCard :label="$t('dashboard.inProgressLessons')" :value="data.in_progress_lessons_count" icon="layers" tone="sky" />
            </div>

            <div v-if="data.recently_accessed_lessons.length" class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                <div class="px-5 py-4"><h2 class="font-semibold text-ink-900">{{ $t('dashboard.continueLeftOff') }}</h2></div>
                <div class="divide-y divide-ink-100">
                    <router-link
                        v-for="l in data.recently_accessed_lessons"
                        :key="l.id"
                        :to="`/student/lessons/${l.lesson_id}`"
                        class="flex items-center gap-4 px-5 py-3.5 hover:bg-parchment-50"
                    >
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600"><Icon name="play" :size="20" /></div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink-800">{{ l.lesson?.title || `${$t('nav.lesson')} #${l.lesson_id}` }}</p>
                            <p class="text-xs text-ink-400">{{ l.progress_percentage }}% · {{ fmtDate(l.updated_at) }}</p>
                        </div>
                        <span class="text-xs font-medium text-terracotta-600">{{ $t('dashboard.resume') }}</span>
                    </router-link>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-ink-900">{{ $t('dashboard.myCourses') }}</h2>
                <router-link to="/student/courses" class="text-sm font-medium text-terracotta-600 hover:underline">{{ $t('dashboard.viewAll') }}</router-link>
            </div>

            <EmptyState
                v-if="!data.courses.length"
                icon="book"
                :title="$t('dashboard.noCoursesYet')"
                :message="$t('dashboard.browseAndEnroll')"
            >
                <router-link to="/courses"><AppButton>{{ $t('dashboard.browseCourses') }}</AppButton></router-link>
            </EmptyState>
            <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <router-link v-for="c in data.courses" :key="c.id" :to="`/student/courses/${c.id}`" class="group">
                    <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm transition group-hover:shadow-md">
                        <div class="flex h-32 items-center justify-center bg-gradient-to-br from-ink-800 to-ink-900 text-white">
                            <Icon name="book" :size="36" class="text-terracotta-400" />
                        </div>
                        <div class="p-5">
                            <h3 class="font-semibold text-ink-900 group-hover:text-terracotta-700" dir="auto">{{ c.title }}</h3>
                            <p class="mt-1 line-clamp-2 text-sm text-ink-500" dir="auto">{{ c.description }}</p>
                            <div class="mt-3 flex items-center justify-between text-xs text-ink-400">
                                <span>{{ c.units_count }} {{ $t('courses.units') }} · {{ c.lessons_count }} {{ $t('courses.lessons') }}</span>
                                <span>{{ c.progress }}%</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-ink-100">
                                <div class="h-full rounded-full bg-terracotta-500 transition-all" :style="{ width: `${c.progress}%` }" />
                            </div>
                        </div>
                    </div>
                </router-link>
            </div>
        </template>
    </div>
</template>
