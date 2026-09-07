<script setup>
import { onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { publicCatalog, toList } from '@/api';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSkeleton from '@/components/ui/AppSkeleton.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const { t } = useI18n();
const { loading, error, data } = useAsync(async () => {
    const res = await publicCatalog.courses({ per_page: 6 });
    return toList(res);
}, { immediate: true });

const stats = [
    { key: 'dashboard.statCourses', icon: 'book' },
    { key: 'dashboard.statLessonsCompleted', icon: 'layers' },
    { key: 'dashboard.statExams', icon: 'clipboard' },
    { key: 'dashboard.statCompetitions', icon: 'trophy' },
];
const features = [
    { k: 'landing.feature_1_t', d: 'landing.feature_1_d', icon: 'layers' },
    { k: 'landing.feature_2_t', d: 'landing.feature_2_d', icon: 'clipboard' },
    { k: 'landing.feature_3_t', d: 'landing.feature_3_d', icon: 'trophy' },
    { k: 'landing.feature_4_t', d: 'landing.feature_4_d', icon: 'play' },
];
const steps = [
    { k: 'landing.how_1_t', d: 'landing.how_1_d', icon: 'user' },
    { k: 'landing.how_2_t', d: 'landing.how_2_d', icon: 'book' },
    { k: 'landing.how_3_t', d: 'landing.how_3_d', icon: 'trophy' },
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
                    <span class="font-display text-lg font-semibold text-ink-900" dir="auto">{{ $t('app.brand') }}</span>
                </div>
                <nav class="flex items-center gap-2">
                    <router-link to="/courses" class="rounded-lg px-3 py-2 text-sm font-medium text-ink-600 hover:bg-ink-100">{{ $t('nav.courses') }}</router-link>
                    <LanguageSwitcher class="me-1" />
                    <router-link to="/login"><AppButton>{{ $t('auth.signIn') }}</AppButton></router-link>
                </nav>
            </div>
        </header>

        <section class="relative overflow-hidden">
            <div class="absolute inset-0 opacity-30" style="background: radial-gradient(45% 45% at 85% 15%, #d95a2b55, transparent), radial-gradient(50% 50% at 8% 90%, #5c749680, transparent);" />
            <div class="relative mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 lg:grid-cols-2">
                <div>
                    <p class="inline-flex items-center gap-2 rounded-full border border-terracotta-200 bg-terracotta-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-terracotta-700">
                        <Icon name="compass" :size="14" /> {{ $t('landing.eyebrow') }}
                    </p>
                    <h1 class="mt-4 font-display text-4xl font-bold leading-tight text-ink-900 sm:text-5xl" dir="auto">{{ $t('landing.heroTitle') }}</h1>
                    <p class="mt-4 max-w-lg text-ink-600" dir="auto">{{ $t('landing.heroSubtitle') }}</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <router-link to="/login"><AppButton size="lg">{{ $t('landing.ctaStart') }}</AppButton></router-link>
                        <router-link to="/courses"><AppButton size="lg" variant="outline">{{ $t('nav.courses') }}</AppButton></router-link>
                    </div>
                    <p class="mt-6 text-sm text-ink-400" dir="auto">{{ $t('landing.trust') }}</p>
                </div>

                <!-- Creative compass-orbit illustration -->
                <div class="relative mx-auto h-64 w-64 sm:h-72 sm:w-72">
                    <div class="absolute inset-0 animate-[spin_14s_linear_infinite] rounded-full border-2 border-dashed border-terracotta-300/70" />
                    <div class="absolute inset-6 rounded-full border border-ink-200" />
                    <div class="absolute inset-12 rounded-full bg-gradient-to-br from-terracotta-100 to-amber-50 flex items-center justify-center text-6xl">🧭</div>
                    <div class="absolute left-1/2 top-0 -translate-x-1/2 -translate-y-1/2 text-base">📍</div>
                    <div class="absolute right-0 top-1/2 -translate-y-1/2 text-base">🌍</div>
                    <div class="absolute bottom-0 left-1/2 -translate-x-1/2 translate-y-1/2 text-base">🗺️</div>
                </div>
            </div>
        </section>

        <!-- Stats strip -->
        <section class="bg-white/60 py-6 backdrop-blur">
            <div class="mx-auto grid max-w-6xl grid-cols-2 gap-4 px-4 sm:grid-cols-4">
                <div v-for="s in stats" :key="s.key" class="flex items-center gap-3 rounded-xl border border-ink-100 bg-white px-4 py-3 shadow-sm">
                    <Icon :name="s.icon" :size="20" class="shrink-0 text-terracotta-500" />
                    <span class="text-sm font-medium text-ink-700">{{ $t(s.key) }}</span>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-center text-2xl font-bold text-ink-900">{{ $t('landing.featuresTitle') }}</h2>
            <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="f in features" :key="f.k" class="rounded-2xl border border-ink-100 bg-white p-5 shadow-sm">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600">
                        <Icon :name="f.icon" :size="20" />
                    </div>
                    <h3 class="mt-4 font-semibold text-ink-900">{{ $t(f.k) }}</h3>
                    <p class="mt-1 text-sm text-ink-500">{{ $t(f.d) }}</p>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section class="bg-ink-900 py-16 text-white">
            <div class="mx-auto max-w-6xl px-4">
                <h2 class="text-center text-2xl font-bold">{{ $t('landing.howTitle') }}</h2>
                <div class="mt-10 grid gap-8 text-center sm:grid-cols-3">
                    <div v-for="(s, i) in steps" :key="s.k">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-terracotta-500 text-white">
                            <Icon :name="s.icon" :size="22" />
                        </div>
                        <p class="mt-3 text-xs font-semibold uppercase tracking-widest text-terracotta-300">{{ $t('common.step', { n: i + 1 }) }}</p>
                        <h3 class="mt-1 font-semibold" dir="auto">{{ $t(s.k) }}</h3>
                        <p class="mt-2 text-sm text-white/70" dir="auto">{{ $t(s.d) }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Featured courses -->
        <section class="mx-auto max-w-6xl px-4 py-16">
            <div class="mb-6 flex items-center justify-between">
                <h2 class="text-2xl font-bold text-ink-900">{{ $t('landing.coursesTitle') }}</h2>
                <router-link to="/courses" class="text-sm font-medium text-terracotta-600 hover:underline">{{ $t('dashboard.viewAll') }}</router-link>
            </div>

            <div v-if="loading" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <AppCard v-for="i in 3" :key="i" class="p-5"><AppSkeleton :rows="4" /></AppCard>
            </div>
            <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $t('common.error') }}</div>
            <div v-else-if="!data.items.length" class="rounded-lg border border-dashed border-ink-200 bg-white px-4 py-8 text-center text-ink-500">{{ $t('courses.empty') }}</div>
            <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <router-link v-for="c in data.items" :key="c.id" :to="`/courses/${c.id}`" class="group">
                    <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm transition group-hover:shadow-md">
                        <div class="relative flex h-36 items-center justify-center bg-gradient-to-br from-ink-800 to-ink-900 text-white">
                            <Icon name="book" :size="40" class="text-terracotta-400" />
                            <span class="absolute start-3 top-3 rounded-full bg-black/30 px-2 py-0.5 text-xs font-medium capitalize text-white/90">{{ c.status }}</span>
                        </div>
                        <div class="p-5">
                            <h3 class="font-semibold text-ink-900 group-hover:text-terracotta-700" dir="auto">{{ c.title }}</h3>
                            <p class="mt-1 line-clamp-2 text-sm text-ink-500" dir="auto">{{ c.description }}</p>
                            <div class="mt-3 flex items-center gap-3 text-xs text-ink-400">
                                <span>{{ $t('courses.lessons') }} {{ c.lessons_count || 0 }}</span>
                                <span class="h-1 w-1 rounded-full bg-ink-300" />
                                <span>{{ $t('courses.units') }} {{ c.units_count || 0 }}</span>
                            </div>
                        </div>
                    </div>
                </router-link>
            </div>
        </section>

        <footer class="border-t border-ink-100 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-8 text-sm text-ink-400">
                <p dir="auto">{{ $t('landing.footerRights', { year: new Date().getFullYear() }) }}</p>
                <p class="mt-1" dir="auto">{{ $t('landing.footerMade') }}</p>
            </div>
        </footer>
    </div>
</template>
