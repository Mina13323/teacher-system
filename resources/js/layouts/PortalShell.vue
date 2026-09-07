<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';
import Icon from '@/components/ui/Icon.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const props = defineProps({
    nav: { type: Array, default: () => [] },
    brand: { type: String, default: 'Atlas Academy' },
    subtitle: { type: String, default: '' },
    roleLabel: { type: String, default: '' },
});

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const notifications = useNotificationsStore();
const mobileOpen = ref(false);

const visibleNav = computed(() =>
    props.nav.filter((n) => !n.roles || n.roles.some((r) => auth.roles.includes(r))),
);

function isActive(to) {
    return route.path === to || route.path.startsWith(to + '/');
}

async function logout() {
    await auth.logout();
    router.push('/login');
}

function initials(name) {
    return (name || 'U').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}
</script>

<template>
    <div class="min-h-screen bg-parchment-50">
        <!-- Sidebar (desktop) -->
        <aside class="fixed inset-y-0 start-0 z-30 hidden w-64 flex-col border-e border-ink-100 bg-white lg:flex">
            <div class="flex h-16 items-center gap-2 border-b border-ink-100 px-5">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-white">
                    <Icon name="compass" :size="20" />
                </div>
                <div class="min-w-0">
                    <p class="truncate font-display text-base font-semibold text-ink-900" dir="auto">{{ brand }}</p>
                    <p class="truncate text-[11px] uppercase tracking-wide text-ink-400">{{ roleLabel }}</p>
                </div>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                <router-link
                    v-for="n in visibleNav"
                    :key="n.to"
                    :to="n.to"
                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition"
                    :class="isActive(n.to) ? 'bg-terracotta-50 text-terracotta-700' : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900'"
                >
                    <Icon :name="n.icon" :size="18" />
                    <span>{{ n.label }}</span>
                </router-link>
            </nav>

            <div class="border-t border-ink-100 p-3">
                <button
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-600 transition hover:bg-ink-50 hover:text-ink-900"
                    @click="logout"
                >
                    <Icon name="home" :size="18" />
                    <span>{{ $t('app.signout') }}</span>
                </button>
            </div>
        </aside>

        <!-- Topbar -->
        <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-ink-100 bg-white/90 px-4 backdrop-blur lg:ps-72">
            <div class="flex items-center gap-3">
                <button class="rounded-lg p-2 text-ink-600 hover:bg-ink-100 lg:hidden" :aria-label="$t('app.openMenu')" @click="mobileOpen = true">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                </button>
                <div>
                    <p class="text-sm font-semibold text-ink-900">{{ subtitle }}</p>
                    <p class="text-xs text-ink-400">{{ auth.displayName }}</p>
                </div>
            </div>

            <div class="flex items-center gap-1">
                <LanguageSwitcher class="me-2" />
                <router-link :to="`/${auth.role}/notifications`" class="relative rounded-lg p-2 text-ink-600 hover:bg-ink-100" :aria-label="$t('app.notifications')">
                    <Icon name="bell" :size="20" />
                    <span v-if="notifications.unread" class="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-terracotta-600 px-1 text-[10px] font-bold text-white">
                        {{ notifications.unread }}
                    </span>
                </router-link>
                <router-link :to="`/${auth.role}/profile`" class="flex items-center gap-2 rounded-lg p-2 hover:bg-ink-100">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-ink-900 text-xs font-bold text-white">{{ initials(auth.displayName) }}</div>
                </router-link>
            </div>
        </header>

        <!-- Mobile sidebar -->
        <transition name="fade">
            <div v-if="mobileOpen" class="fixed inset-0 z-40 lg:hidden">
                <div class="absolute inset-0 bg-ink-900/40" @click="mobileOpen = false" />
                <div class="absolute inset-y-0 start-0 w-64 bg-white p-4 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="font-display text-lg font-semibold text-ink-900" dir="auto">{{ brand }}</p>
                        <button class="rounded p-1 text-ink-500 hover:bg-ink-100" :aria-label="$t('app.close')" @click="mobileOpen = false">
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                        </button>
                    </div>
                    <nav class="space-y-1">
                        <router-link
                            v-for="n in visibleNav"
                            :key="n.to"
                            :to="n.to"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-600 hover:bg-ink-50"
                            @click="mobileOpen = false"
                        >
                            <Icon :name="n.icon" :size="18" />
                            <span>{{ n.label }}</span>
                        </router-link>
                    </nav>
                    <button class="mt-4 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-600 hover:bg-ink-50" @click="logout">
                        <Icon name="home" :size="18" />
                        <span>{{ $t('app.signout') }}</span>
                    </button>
                </div>
            </div>
        </transition>

        <!-- Main content -->
        <main class="min-h-[calc(100vh-4rem)] px-4 py-6 lg:ps-72 lg:pe-8">
            <div class="mx-auto max-w-5xl">
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
