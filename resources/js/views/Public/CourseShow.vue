<script setup>
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useAsync } from '@/composables/useAsync';
import { publicCatalog, toList } from '@/api';
import Icon from '@/components/ui/Icon.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';

const route = useRoute();
const { loading, error, data, run } = useAsync(() => publicCatalog.course(route.params.id));

onMounted(() => run());

// `units` (+ each unit's `lessons` + each lesson's `videos`) are nested resource
// collections (`{ data: [...] }`), so normalise to plain arrays.
const units = computed(() => {
    const us = data.value?.units;
    if (!us) return [];
    const arr = Array.isArray(us) ? us : (us.data || []);
    return arr.map((u) => {
        const lessonsRaw = Array.isArray(u.lessons) ? u.lessons : (u.lessons?.data || []);
        return {
            ...u,
            lessons: lessonsRaw.map((l) => ({
                ...l,
                videos: Array.isArray(l.videos) ? l.videos : (l.videos?.data || []),
            })),
        };
    });
});
</script>

<template>
    <div class="min-h-screen bg-parchment-50">
        <header class="sticky top-0 z-20 border-b border-ink-100 bg-parchment-50/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-4">
                <div class="flex items-center gap-2">
                    <Icon name="compass" :size="22" class="text-terracotta-600" />
                    <span class="font-display text-lg font-semibold text-ink-900">Atlas Academy</span>
                </div>
                <nav class="flex items-center gap-3">
                    <router-link to="/courses" class="text-sm font-medium text-ink-600 hover:text-ink-900">Courses</router-link>
                    <router-link to="/login"><AppButton size="sm">Sign in</AppButton></router-link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-10">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>
            <template v-else>
                <router-link to="/courses" class="text-sm font-medium text-terracotta-600 hover:underline">← All courses</router-link>
                <div class="mt-4 rounded-xl border border-ink-100 bg-white p-6 shadow-sm">
                    <span class="inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 capitalize">{{ data.status }}</span>
                    <h1 class="mt-3 font-display text-3xl font-bold text-ink-900">{{ data.title }}</h1>
                    <p class="mt-2 max-w-2xl text-ink-600">{{ data.description }}</p>
                    <div class="mt-4 flex items-center gap-3 text-sm text-ink-400">
                        <span>Units {{ data.units_count || 0 }}</span>
                        <span class="h-1 w-1 rounded-full bg-ink-300" />
                        <span>Lessons {{ data.lessons_count || 0 }}</span>
                    </div>
                </div>

                <div class="mt-8 space-y-6">
                    <div v-for="unit in units" :key="unit.id" class="rounded-xl border border-ink-100 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-ink-900">{{ unit.title }}</h2>
                        <p v-if="unit.description" class="mt-1 text-sm text-ink-500">{{ unit.description }}</p>
                        <div class="mt-4 space-y-2">
                            <div v-for="lesson in unit.lessons" :key="lesson.id" class="flex items-start gap-3 rounded-lg bg-parchment-50 px-4 py-3">
                                <Icon name="play" :size="18" class="mt-0.5 text-terracotta-500" />
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-ink-800">{{ lesson.title }}</p>
                                    <p v-if="lesson.videos?.length" class="text-xs text-ink-400">{{ lesson.videos.length }} video(s)</p>
                                </div>
                                <router-link to="/login" class="text-xs font-medium text-terracotta-600 hover:underline">Sign in to study</router-link>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </main>
    </div>
</template>
