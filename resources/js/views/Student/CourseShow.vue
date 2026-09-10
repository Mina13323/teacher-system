<script setup>
import { onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { student } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Icon from '@/components/ui/Icon.vue';

const { t } = useI18n();
const route = useRoute();
const { loading, error, data, run } = useAsync(() => student.course(route.params.id));
onMounted(() => run());

function badgeTone(status) {
    return { completed: 'success', in_progress: 'warning', available: 'info', locked: 'neutral', not_started: 'neutral' }[status] || 'neutral';
}
function statusLabel(status) {
    return status ? t(`status.${status}`, status) : '';
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link to="/student/courses" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('dashboard.myCourses') }}</router-link>
            <h1 class="mt-2 text-2xl font-bold text-ink-900" dir="auto">{{ data?.title || $t('common.course') }}</h1>
            <p v-if="data?.description" class="mt-1 text-ink-600" dir="auto">{{ data.description }}</p>
            <div class="mt-4 flex items-center gap-3 text-sm text-ink-500">
                <span>{{ $t('courses.overallProgress') }}</span>
                <span class="font-semibold text-ink-800">{{ data?.progress ?? 0 }}%</span>
            </div>
            <div class="mt-1 h-2 w-64 overflow-hidden rounded-full bg-ink-100">
                <div class="h-full rounded-full bg-terracotta-500" :style="{ width: `${data?.progress ?? 0}%` }" />
            </div>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <EmptyState v-if="!data?.units?.length" icon="layers" :title="$t('courses.noUnitsTitle')" :message="$t('courses.noUnitsMessage')" />
            <div v-else class="space-y-5">
                <div v-for="unit in data.units" :key="unit.id" class="rounded-xl border border-ink-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold text-ink-900" dir="auto">{{ unit.title }}</h2>
                        <span class="text-xs text-ink-400">{{ $t('common.unitN', { n: unit.position }) }}</span>
                    </div>
                    <div class="mt-3 space-y-2">
                        <router-link
                            v-for="lesson in unit.lessons"
                            :key="lesson.id"
                            :to="`/student/lessons/${lesson.id}`"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition hover:bg-parchment-50"
                            :class="lesson.status === 'locked' ? 'pointer-events-none opacity-60' : ''"
                        >
                            <div
                                class="flex h-8 w-8 items-center justify-center rounded-full"
                                :class="lesson.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : lesson.status === 'in_progress' ? 'bg-amber-100 text-amber-700' : 'bg-ink-100 text-ink-500'"
                            >
                                <Icon v-if="lesson.status === 'completed'" name="check" :size="16" />
                                <Icon v-else-if="lesson.status === 'in_progress'" name="play" :size="16" />
                                <span v-else class="text-xs font-bold">{{ unit.lessons.findIndex((l) => l.id === lesson.id) + 1 }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800" dir="auto">{{ lesson.title }}</p>
                            </div>
                            <AppBadge :tone="badgeTone(lesson.status)">{{ statusLabel(lesson.status) }}</AppBadge>
                        </router-link>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
