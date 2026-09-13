<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';
import VideoHook from './VideoHook.vue';

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
    return auth.isAuthenticated ? 'landing.ctaDashboard' : 'landing.ctaStart';
});
</script>

<template>
    <section class="relative overflow-hidden bg-parchment-50 pb-20 pt-12 sm:pb-28 sm:pt-16">
        <!-- Geographic Map Grid Background Pattern -->
        <div class="absolute inset-0 opacity-40 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:36px_36px]"></div>

        <!-- Radial Ambient Gradients -->
        <div class="pointer-events-none absolute -top-40 start-1/2 -z-10 h-[600px] w-[800px] -translate-x-1/2 rounded-full bg-gradient-to-tr from-terracotta-200/30 via-amber-100/20 to-transparent blur-3xl"></div>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Hero Header Copy -->
            <div class="mx-auto max-w-3xl text-center">
                <!-- Eyebrow Badge with Compass Icon -->
                <div class="inline-flex items-center gap-2.5 rounded-full border border-terracotta-200 bg-terracotta-50/80 px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-terracotta-800 shadow-sm backdrop-blur">
                    <Icon name="compass" :size="15" class="text-terracotta-600" />
                    <span>{{ $t('landing.eyebrow') }}</span>
                </div>

                <!-- Main Title -->
                <h1 class="mt-6 font-display text-4xl font-extrabold tracking-tight text-ink-900 sm:text-5xl lg:text-6xl lg:leading-[1.15]" dir="auto">
                    {{ $t('landing.heroTitle') }}
                </h1>

                <!-- Subtitle -->
                <p class="mt-6 text-lg leading-relaxed text-ink-600 sm:text-xl" dir="auto">
                    {{ $t('landing.heroSubtitle') }}
                </p>

                <!-- Primary CTAs -->
                <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
                    <router-link :to="ctaUrl">
                        <AppButton size="lg" variant="primary" class="shadow-md">
                            <Icon name="sparkles" :size="18" class="me-2" />
                            {{ $t(ctaLabel) }}
                        </AppButton>
                    </router-link>
                    <a href="#curriculum">
                        <AppButton size="lg" variant="outline" class="border-ink-300 bg-white/80 hover:bg-white">
                            <Icon name="compass" :size="18" class="me-2 text-terracotta-600" />
                            {{ $t('landing.ctaExplore') }}
                        </AppButton>
                    </a>
                </div>
            </div>

            <!-- Video Hook Section -->
            <div class="mt-14 sm:mt-18">
                <VideoHook />
            </div>
        </div>
    </section>
</template>
