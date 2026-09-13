<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';

const auth = useAuthStore();

const ctaUrl = computed(() => {
    if (!auth.isAuthenticated) return '/login';
    if (auth.roles.includes('admin')) return '/admin';
    if (auth.roles.includes('teacher')) return '/teacher';
    if (auth.roles.includes('assistant')) return '/assistant';
    if (auth.roles.includes('student')) return '/student';
    return '/';
});

const ctaLabel = computed(() => {
    return auth.isAuthenticated ? 'landing.ctaDashboard' : 'landing.ctaLogin';
});

const conceptualDomains = [
    {
        icon: 'compass',
        tag: 'GEOGRAPHY',
        titleEn: 'Physical Geography & Cartography',
        titleAr: 'الجغرافيا الطبيعية ورسم الخرائط',
        descEn: 'Master topographic contours, GIS coordinate systems, atmospheric dynamics, and physical landforms.',
        descAr: 'دراسة الخطوط الكنتورية، وإحداثيات GIS، والأنظمة الجوية، والتضاريس الطبيعية.',
        badge: 'Spatial Systems',
    },
    {
        icon: 'book',
        tag: 'HISTORY',
        titleEn: 'World History & Ancient Civilizations',
        titleAr: 'التاريخ العالمي والحضارات القديمة',
        descEn: 'Trace river valley societies, early governance models, maritime trade routes, and monumental architecture.',
        descAr: 'تتبع مجتمعات أودية الأنهار، ونظم الحكم الأولى، وطرق التجارة البحرية، والآثار العمرانية.',
        badge: 'Chronological',
    },
    {
        icon: 'sparkles',
        tag: 'GEOGRAPHY',
        titleEn: 'Geopolitics & Human Geography',
        titleAr: 'الجيوسياسة والجغرافيا البشرية',
        descEn: 'Examine population migration, resource allocation, state boundaries, and global trade corridors.',
        descAr: 'تحليل الهجرات السكانية، وتوزيع الموارد، والحدود السياسية، وممرات التجارة العالمية.',
        badge: 'Human Systems',
    },
    {
        icon: 'user',
        tag: 'HISTORY',
        titleEn: 'Modern History & Primary Source Analysis',
        titleAr: 'التاريخ المعاصر وتحليل المصادر',
        descEn: 'Deconstruct modern geopolitical alliances, industrial revolutions, treaty documents, and historical cause-and-effect.',
        descAr: 'تحليل التحالفات المعاصرة، والثورات الصناعية، والوثائق التاريخية، وتتبع الأسباب والنتائج.',
        badge: 'Analytical',
    },
];
</script>

<template>
    <section id="curriculum" class="relative bg-white py-20 sm:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="mb-12 flex flex-col justify-between gap-6 sm:flex-row sm:items-end">
                <div>
                    <span class="text-xs font-bold uppercase tracking-widest text-terracotta-700">
                        {{ $t('landing.curriculumTitle') }}
                    </span>
                    <h2 class="mt-2 font-display text-3xl font-bold tracking-tight text-ink-900 sm:text-4xl" dir="auto">
                        {{ $t('landing.coursesTitle') }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-600" dir="auto">
                        {{ $t('landing.coursesSubtitle') }}
                    </p>
                </div>

                <router-link :to="ctaUrl">
                    <AppButton variant="outline" size="md" class="border-ink-300 bg-parchment-50 hover:bg-white">
                        <Icon name="compass" :size="16" class="me-2 text-terracotta-600" />
                        {{ $t(ctaLabel) }}
                    </AppButton>
                </router-link>
            </div>

            <!-- Conceptual Curriculum Domain Cards (Pure Marketing, Zero Private API Fetch) -->
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="(domain, idx) in conceptualDomains"
                    :key="idx"
                    class="group flex flex-col justify-between overflow-hidden rounded-2xl border border-ink-200/90 bg-parchment-50/50 p-6 transition-all duration-300 hover:-translate-y-1 hover:border-terracotta-300 hover:bg-white hover:shadow-lg"
                >
                    <div>
                        <!-- Badge & Icon -->
                        <div class="flex items-center justify-between">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-terracotta-100 text-terracotta-700 transition-colors group-hover:bg-terracotta-600 group-hover:text-white">
                                <Icon :name="domain.icon" :size="22" />
                            </div>
                            <span class="rounded-full border border-terracotta-200/60 bg-terracotta-50 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-terracotta-800">
                                {{ domain.badge }}
                            </span>
                        </div>

                        <!-- Card Copy -->
                        <h3 class="mt-5 font-display text-base font-bold text-ink-900 transition-colors group-hover:text-terracotta-700" dir="auto">
                            {{ $i18n.locale === 'ar' ? domain.titleAr : domain.titleEn }}
                        </h3>

                        <p class="mt-2 text-xs leading-relaxed text-ink-600" dir="auto">
                            {{ $i18n.locale === 'ar' ? domain.descAr : domain.descEn }}
                        </p>
                    </div>

                    <!-- Protected Access Footer Hint -->
                    <div class="mt-6 flex items-center justify-between border-t border-ink-100/80 pt-4 text-[11px] font-medium text-ink-500">
                        <span class="inline-flex items-center gap-1 text-terracotta-700">
                            <Icon name="compass" :size="12" />
                            {{ domain.tag }}
                        </span>
                        <router-link :to="ctaUrl" class="inline-flex items-center gap-1 transition-colors hover:text-terracotta-700">
                            <span>{{ auth.isAuthenticated ? $t('landing.ctaDashboard') : $t('auth.signIn') }}</span>
                            <Icon name="chevronRight" :size="14" />
                        </router-link>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
