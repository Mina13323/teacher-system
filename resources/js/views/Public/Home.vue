<script setup>
import { onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { publicCatalog } from '@/api';
import { toList } from '@/api';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSkeleton from '@/components/ui/AppSkeleton.vue';

const { loading, error, data, run } = useAsync(async () => {
    const res = await publicCatalog.courses({ per_page: 6 });
    return toList(res);
}, { immediate: true });

onMounted(run);

const statItems = [
    { label: 'Courses', icon: 'book' },
    { label: 'Lessons', icon: 'layers' },
    { label: 'Exams', icon: 'clipboard' },
    { label: 'Competitions', icon: 'trophy' },
];
</script>

<template>
    <div class="min-h-screen bg-parchment-50">
        <header class="sticky top-0 z-20 border-b border-ink-100 bg-parchment-50/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
                <div class="flex items-center gap-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-white">
                        <Icon name="compass" :size="20" />
                    </div>
                    <span class="font-display text-lg font-semibold text-ink-900">Atlas Academy</span>
                </div>
                <nav class="flex items-center gap-2">
                    <router-link to="/courses" class="rounded-lg px-3 py-2 text-sm font-medium text-ink-600 hover:bg-ink-100">Courses</router-link>
                    <router-link to="/login" class="rounded-lg px-3 py-2 text-sm font-medium text-ink-700 hover:bg-ink-100">Sign in</router-link>
                    <router-link to="/register"><AppButton>Get started</AppButton></router-link>
                </nav>
            </div>
        </header>

        <section class="relative overflow-hidden bg-ink-900 text-white">
            <div class="absolute inset-0 opacity-20" style="background: radial-gradient(50% 50% at 85% 20%, #d95a2b55, transparent), radial-gradient(60% 60% at 10% 90%, #5c749680, transparent);" />
            <div class="relative mx-auto grid max-w-6xl gap-10 px-4 py-20 lg:grid-cols-2 lg:items-center">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-widest text-terracotta-300">Geography · History</p>
                    <h1 class="mt-4 font-display text-4xl font-bold leading-tight sm:text-5xl">Journey across time and terrain.</h1>
                    <p class="mt-4 max-w-lg text-white/70">Master the world's places and the events that shaped them — through structured courses, engaging lessons, exams and competitions.</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <router-link to="/register"><AppButton size="lg" variant="primary">Start learning</AppButton></router-link>
                        <router-link to="/courses"><AppButton size="lg" variant="outline" class="border-white/30 bg-white/10 text-white hover:bg-white/20">Browse courses</AppButton></router-link>
                    </div>
                </div>
                <div class="hidden lg:block">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-6 backdrop-blur">
                        <div class="grid grid-cols-2 gap-4">
                            <div v-for="s in statItems" :key="s.label" class="rounded-xl bg-white/5 p-4">
                                <Icon :name="s.icon" :size="22" class="text-terracotta-300" />
                                <p class="mt-2 text-sm text-white/70">{{ s.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-4 py-16">
            <div class="mb-6 flex items-center justify-between">
                <h2 class="text-2xl font-bold text-ink-900">Featured courses</h2>
                <router-link to="/courses" class="text-sm font-medium text-terracotta-600 hover:underline">View all</router-link>
            </div>

            <div v-if="loading" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <AppCard v-for="i in 3" :key="i" class="p-5"><AppSkeleton :rows="4" /></AppCard>
            </div>
            <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">Could not load courses.</div>
            <div v-else-if="!data.items.length" class="rounded-lg border border-dashed border-ink-200 bg-white px-4 py-8 text-center text-ink-500">No published courses yet.</div>
            <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <router-link v-for="c in data.items" :key="c.id" :to="`/courses/${c.id}`" class="group">
                    <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm transition group-hover:shadow-md">
                        <div class="relative flex h-36 items-center justify-center bg-gradient-to-br from-ink-800 to-ink-900 text-white">
                            <Icon name="book" :size="40" class="text-terracotta-400" />
                            <span class="absolute left-3 top-3 rounded-full bg-black/30 px-2 py-0.5 text-xs font-medium capitalize text-white/90">{{ c.status }}</span>
                        </div>
                        <div class="p-5">
                            <h3 class="font-semibold text-ink-900 group-hover:text-terracotta-700">{{ c.title }}</h3>
                            <p class="mt-1 line-clamp-2 text-sm text-ink-500">{{ c.description }}</p>
                            <div class="mt-3 flex items-center gap-3 text-xs text-ink-400">
                                <span>Lessons {{ c.lessons_count || 0 }}</span>
                                <span class="h-1 w-1 rounded-full bg-ink-300" />
                                <span>Units {{ c.units_count || 0 }}</span>
                            </div>
                        </div>
                    </div>
                </router-link>
            </div>
        </section>

        <footer class="border-t border-ink-100 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-8 text-sm text-ink-400">
                <p>© {{ new Date().getFullYear() }} Atlas Academy. Geography &amp; History learning, crafted with care.</p>
            </div>
        </footer>
    </div>
</template>
