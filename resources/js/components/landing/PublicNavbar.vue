<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const auth = useAuthStore();

const dashboardUrl = computed(() => {
    if (auth.roles.includes('admin')) return '/admin';
    if (auth.roles.includes('teacher')) return '/teacher';
    if (auth.roles.includes('assistant')) return '/assistant';
    if (auth.roles.includes('student')) return '/student';
    return '/';
});

const userRoleLabel = computed(() => {
    if (auth.roles.includes('admin')) return 'Admin';
    if (auth.roles.includes('teacher')) return 'Teacher';
    if (auth.roles.includes('assistant')) return 'Assistant';
    if (auth.roles.includes('student')) return 'Student';
    return '';
});
</script>

<template>
    <header class="sticky top-0 z-40 border-b border-ink-200/80 bg-parchment-50/90 backdrop-blur-md transition-all duration-300">
        <div class="mx-auto flex h-20 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
            <!-- Brand Identity -->
            <router-link to="/" class="group flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-terracotta-600 to-terracotta-700 text-white shadow-md transition-transform duration-300 group-hover:scale-105">
                    <Icon name="compass" :size="24" />
                </div>
                <div class="flex flex-col">
                    <span class="font-display text-xl font-bold tracking-tight text-ink-900" dir="auto">
                        {{ $t('app.brand') }}
                    </span>
                    <span class="text-xs font-medium uppercase tracking-widest text-terracotta-700" dir="auto">
                        {{ $t('app.tagline') }}
                    </span>
                </div>
            </router-link>

            <!-- Desktop Navigation -->
            <nav class="hidden items-center gap-8 md:flex">
                <a href="#philosophy" class="text-sm font-medium text-ink-700 transition hover:text-terracotta-600">
                    {{ $t('landing.philosophyTag') }}
                </a>
                <a href="#geography" class="text-sm font-medium text-ink-700 transition hover:text-terracotta-600">
                    {{ $t('landing.geographyTag') }}
                </a>
                <a href="#history" class="text-sm font-medium text-ink-700 transition hover:text-terracotta-600">
                    {{ $t('landing.historyTag') }}
                </a>
                <a href="#curriculum" class="text-sm font-medium text-ink-700 transition hover:text-terracotta-600">
                    {{ $t('landing.coursesTitle') }}
                </a>
            </nav>

            <!-- Actions (Auth State Dependent) -->
            <div class="flex items-center gap-3 sm:gap-4">
                <LanguageSwitcher />

                <template v-if="auth.isAuthenticated">
                    <div class="hidden items-center gap-2 rounded-full border border-ink-200 bg-white/80 px-3 py-1 text-xs sm:flex">
                        <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="font-semibold text-ink-800">{{ auth.user?.name || auth.user?.email }}</span>
                        <span class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] uppercase font-bold text-ink-600">{{ userRoleLabel }}</span>
                    </div>
                    <router-link :to="dashboardUrl">
                        <AppButton variant="primary" size="md" class="shadow-sm">
                            <Icon name="user" :size="16" class="me-1.5" />
                            {{ $t('landing.ctaDashboard') }}
                        </AppButton>
                    </router-link>
                </template>

                <template v-else>
                    <router-link to="/login">
                        <AppButton variant="primary" size="md" class="shadow-sm">
                            <Icon name="user" :size="16" class="me-1.5" />
                            {{ $t('auth.signIn') }}
                        </AppButton>
                    </router-link>
                </template>
            </div>
        </div>
    </header>
</template>
