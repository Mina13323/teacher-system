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
    return auth.isAuthenticated ? 'landing.ctaDashboard' : 'landing.ctaStart';
});
</script>

<template>
    <section class="relative overflow-hidden bg-parchment-50 py-20 sm:py-28">
        <!-- Ambient Grid Background -->
        <div class="absolute inset-0 opacity-20 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:32px_32px]"></div>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="relative overflow-hidden rounded-3xl border border-ink-200 bg-gradient-to-br from-ink-900 via-ink-950 to-ink-900 p-10 text-center text-white shadow-2xl sm:p-16">
                <!-- Watermark Icon -->
                <Icon name="compass" :size="240" class="pointer-events-none absolute -bottom-16 -end-16 text-ink-800/30" />

                <div class="relative z-10 mx-auto max-w-2xl">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-terracotta-600 text-white shadow-xl">
                        <Icon name="compass" :size="28" />
                    </div>

                    <h2 class="mt-6 font-display text-3xl font-extrabold tracking-tight sm:text-4xl lg:text-5xl" dir="auto">
                        {{ $t('landing.ctaTitle') }}
                    </h2>

                    <p class="mt-4 text-lg text-ink-300" dir="auto">
                        {{ $t('landing.ctaSubtitle') }}
                    </p>

                    <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
                        <router-link :to="ctaUrl">
                            <AppButton size="lg" variant="primary" class="shadow-lg">
                                <Icon name="sparkles" :size="18" class="me-2" />
                                {{ $t(ctaLabel) }}
                            </AppButton>
                        </router-link>
                        <router-link :to="auth.isAuthenticated ? ctaUrl : '/login'">
                            <AppButton size="lg" variant="outline" class="border-ink-700 bg-ink-900 text-white hover:bg-ink-800">
                                <Icon name="compass" :size="18" class="me-2 text-terracotta-400" />
                                {{ auth.isAuthenticated ? $t('landing.ctaDashboard') : $t('landing.ctaLogin') }}
                            </AppButton>
                        </router-link>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
