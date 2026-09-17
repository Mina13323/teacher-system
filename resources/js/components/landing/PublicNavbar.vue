<script setup>
import { computed, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const auth = useAuthStore();
const mobileOpen = ref(false);

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

function closeMobile() {
    mobileOpen.value = false;
}
</script>

<template>
    <header class="sticky top-0 z-40 border-b border-ink-200/80 bg-parchment-50/95 pt-safe backdrop-blur-md transition-all duration-300">
        <div class="mx-auto flex h-16 sm:h-20 max-w-7xl items-center justify-between px-3 sm:px-6 lg:px-8">
            <!-- Brand Identity -->
            <router-link to="/" class="group flex items-center gap-2.5 sm:gap-3 min-w-0" @click="closeMobile">
                <div class="flex h-9 w-9 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-terracotta-600 to-terracotta-700 text-white shadow-md transition-transform duration-300 group-hover:scale-105">
                    <Icon name="compass" :size="20" class="sm:hidden" />
                    <Icon name="compass" :size="24" class="hidden sm:block" />
                </div>
                <div class="flex flex-col min-w-0">
                    <span class="truncate font-display text-base sm:text-xl font-bold tracking-tight text-ink-900" dir="auto">
                        {{ $t('app.brand') }}
                    </span>
                    <span class="truncate text-[10px] sm:text-xs font-medium uppercase tracking-widest text-terracotta-700" dir="auto">
                        {{ $t('app.tagline') }}
                    </span>
                </div>
            </router-link>

            <!-- Desktop Navigation -->
            <nav class="hidden items-center gap-6 lg:gap-8 md:flex">
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
                <router-link to="/courses" class="text-sm font-medium text-ink-700 transition hover:text-terracotta-600">
                    {{ $t('nav.courses') }}
                </router-link>
            </nav>

            <!-- Actions (Auth State Dependent & Mobile Hamburger) -->
            <div class="flex items-center gap-1.5 sm:gap-3">
                <LanguageSwitcher class="scale-90 sm:scale-100" />

                <!-- Desktop / Tablet Auth Button -->
                <div class="hidden sm:flex sm:items-center sm:gap-2">
                    <template v-if="auth.isAuthenticated">
                        <div class="hidden items-center gap-2 rounded-full border border-ink-200 bg-white/80 px-3 py-1 text-xs lg:flex">
                            <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="font-semibold text-ink-800">{{ auth.user?.name || auth.user?.email }}</span>
                            <span class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] uppercase font-bold text-ink-600">{{ userRoleLabel }}</span>
                        </div>
                        <router-link :to="dashboardUrl">
                            <AppButton variant="primary" size="sm" class="shadow-sm">
                                <Icon name="user" :size="16" class="me-1.5" />
                                {{ $t('landing.ctaDashboard') }}
                            </AppButton>
                        </router-link>
                    </template>

                    <template v-else>
                        <router-link to="/login">
                            <AppButton variant="primary" size="sm" class="shadow-sm">
                                <Icon name="user" :size="16" class="me-1.5" />
                                {{ $t('auth.signIn') }}
                            </AppButton>
                        </router-link>
                    </template>
                </div>

                <!-- Mobile Hamburger Toggle -->
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white text-ink-700 hover:bg-ink-50 md:hidden"
                    :aria-label="mobileOpen ? $t('app.close') : $t('app.openMenu')"
                    @click="mobileOpen = !mobileOpen"
                >
                    <svg v-if="!mobileOpen" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Mobile Drawer Menu -->
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <div v-if="mobileOpen" class="border-b border-ink-200 bg-white/95 px-4 py-4 shadow-lg backdrop-blur-md md:hidden">
                <nav class="flex flex-col space-y-2">
                    <a
                        href="#philosophy"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-700 hover:bg-ink-50 hover:text-terracotta-700"
                        @click="closeMobile"
                    >
                        <Icon name="compass" :size="18" class="text-terracotta-600" />
                        <span>{{ $t('landing.philosophyTag') }}</span>
                    </a>
                    <a
                        href="#geography"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-700 hover:bg-ink-50 hover:text-terracotta-700"
                        @click="closeMobile"
                    >
                        <Icon name="map" :size="18" class="text-terracotta-600" />
                        <span>{{ $t('landing.geographyTag') }}</span>
                    </a>
                    <a
                        href="#history"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-700 hover:bg-ink-50 hover:text-terracotta-700"
                        @click="closeMobile"
                    >
                        <Icon name="book" :size="18" class="text-terracotta-600" />
                        <span>{{ $t('landing.historyTag') }}</span>
                    </a>
                    <a
                        href="#curriculum"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-700 hover:bg-ink-50 hover:text-terracotta-700"
                        @click="closeMobile"
                    >
                        <Icon name="layers" :size="18" class="text-terracotta-600" />
                        <span>{{ $t('landing.coursesTitle') }}</span>
                    </a>
                    <router-link
                        to="/courses"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-700 hover:bg-ink-50 hover:text-terracotta-700"
                        @click="closeMobile"
                    >
                        <Icon name="clipboard" :size="18" class="text-terracotta-600" />
                        <span>{{ $t('nav.courses') }}</span>
                    </router-link>
                </nav>

                <div class="mt-4 border-t border-ink-100 pt-4">
                    <template v-if="auth.isAuthenticated">
                        <div class="mb-3 flex items-center justify-between rounded-lg bg-ink-50 p-2.5">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 shrink-0"></span>
                                <span class="truncate text-xs font-semibold text-ink-800">{{ auth.user?.name || auth.user?.email }}</span>
                            </div>
                            <span class="shrink-0 rounded bg-ink-200 px-1.5 py-0.5 text-[10px] uppercase font-bold text-ink-700">{{ userRoleLabel }}</span>
                        </div>
                        <router-link :to="dashboardUrl" class="block" @click="closeMobile">
                            <AppButton variant="primary" size="md" class="w-full justify-center shadow-sm">
                                <Icon name="user" :size="18" class="me-2" />
                                {{ $t('landing.ctaDashboard') }}
                            </AppButton>
                        </router-link>
                    </template>
                    <template v-else>
                        <router-link to="/login" class="block" @click="closeMobile">
                            <AppButton variant="primary" size="md" class="w-full justify-center shadow-sm">
                                <Icon name="user" :size="18" class="me-2" />
                                {{ $t('auth.signIn') }}
                            </AppButton>
                        </router-link>
                    </template>
                </div>
            </div>
        </transition>
    </header>
</template>
