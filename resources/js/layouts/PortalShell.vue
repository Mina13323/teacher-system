<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';
import Icon from '@/components/ui/Icon.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const props = defineProps({
    nav: { type: Array, default: () => [] },
    brand: { type: String, default: 'El Masry' },
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

// Mobile bottom bar shows first 4 items; 5th button is "More" / Menu drawer
const bottomNavItems = computed(() => {
    const list = visibleNav.value;
    if (list.length <= 4) {
        return list;
    }
    return list.slice(0, 4);
});

const hasMoreInDrawer = computed(() => visibleNav.value.length > 4);

function isActive(to) {
    return route.path === to || route.path.startsWith(to + '/');
}

async function logout() {
    mobileOpen.value = false;
    await auth.logout();
    router.push('/login');
}

function initials(name) {
    return (name || 'U').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}

// Automatically close mobile drawer when route changes
watch(() => route.path, () => {
    mobileOpen.value = false;
});
</script>

<template>
    <div class="min-h-screen bg-parchment-50">
        <!-- Sidebar (desktop) -->
        <aside class="fixed inset-y-0 start-0 z-30 hidden w-64 flex-col border-e border-ink-100 bg-white lg:flex">
            <div class="flex h-16 items-center gap-2.5 border-b border-ink-100 px-5">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-white shadow-sm">
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
                    :class="isActive(n.to) ? 'bg-terracotta-50 text-terracotta-700 font-semibold' : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900'"
                >
                    <Icon :name="n.icon" :size="18" />
                    <span class="truncate">{{ n.label }}</span>
                    <span
                        v-if="n.icon === 'bell' && notifications.unread"
                        class="ms-auto flex h-4 min-w-4 items-center justify-center rounded-full bg-terracotta-600 px-1 text-[10px] font-bold text-white"
                    >
                        {{ notifications.unread }}
                    </span>
                </router-link>
            </nav>

            <div class="border-t border-ink-100 p-3">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-600 transition hover:bg-ink-50 hover:text-ink-900"
                    @click="logout"
                >
                    <Icon name="home" :size="18" />
                    <span>{{ $t('app.signout') }}</span>
                </button>
            </div>
        </aside>

        <!-- Topbar (Mobile & Desktop) -->
        <header class="sticky top-0 z-20 flex min-h-[4rem] h-[calc(4rem+env(safe-area-inset-top,0px))] items-center justify-between border-b border-ink-100 bg-white/95 px-3 sm:px-4 pt-safe backdrop-blur lg:ps-72">
            <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
                <button
                    type="button"
                    class="shrink-0 rounded-lg p-2 text-ink-600 hover:bg-ink-100 lg:hidden"
                    :aria-label="$t('app.openMenu')"
                    @click="mobileOpen = true"
                >
                    <Icon name="menu" :size="20" />
                </button>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink-900" dir="auto">{{ subtitle }}</p>
                    <p class="truncate text-xs text-ink-400" dir="auto">{{ auth.displayName }}</p>
                </div>
            </div>

            <div class="flex items-center gap-1 sm:gap-2 shrink-0">
                <LanguageSwitcher class="me-0.5 sm:me-1 scale-90 sm:scale-100" />
                <router-link
                    :to="`/${auth.role}/notifications`"
                    class="relative rounded-lg p-2 text-ink-600 hover:bg-ink-100"
                    :aria-label="$t('app.notifications')"
                >
                    <Icon name="bell" :size="20" />
                    <span
                        v-if="notifications.unread"
                        class="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-terracotta-600 px-1 text-[10px] font-bold text-white"
                    >
                        {{ notifications.unread }}
                    </span>
                </router-link>
                <router-link
                    :to="`/${auth.role}/profile`"
                    class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-ink-100"
                    :aria-label="$t('app.profile')"
                >
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-ink-900 text-xs font-bold text-white shadow-sm shrink-0">
                        {{ initials(auth.displayName) }}
                    </div>
                </router-link>
            </div>
        </header>

        <!-- Mobile sidebar drawer -->
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="mobileOpen" class="fixed inset-0 z-50 lg:hidden">
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-ink-900/50 backdrop-blur-sm" @click="mobileOpen = false" />

                <!-- Slide Panel -->
                <div class="absolute inset-y-0 start-0 flex h-full w-72 max-w-[85vw] flex-col bg-white shadow-2xl pt-safe pb-safe animate-in slide-in-from-start duration-300">
                    <!-- Drawer Header -->
                    <div class="flex items-center justify-between border-b border-ink-100 p-4 shrink-0">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-white shrink-0 shadow-sm">
                                <Icon name="compass" :size="20" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate font-display text-base font-semibold text-ink-900" dir="auto">{{ brand }}</p>
                                <p class="truncate text-[10px] uppercase tracking-wide text-ink-400">{{ roleLabel }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-ink-500 hover:bg-ink-100 shrink-0"
                            :aria-label="$t('app.close')"
                            @click="mobileOpen = false"
                        >
                            <Icon name="x" :size="20" />
                        </button>
                    </div>

                    <!-- Drawer Nav Items -->
                    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3">
                        <router-link
                            v-for="n in visibleNav"
                            :key="n.to"
                            :to="n.to"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition"
                            :class="isActive(n.to) ? 'bg-terracotta-50 text-terracotta-700 font-semibold' : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900'"
                            @click="mobileOpen = false"
                        >
                            <Icon :name="n.icon" :size="18" />
                            <span class="truncate">{{ n.label }}</span>
                            <span
                                v-if="n.icon === 'bell' && notifications.unread"
                                class="ms-auto flex h-4 min-w-4 items-center justify-center rounded-full bg-terracotta-600 px-1 text-[10px] font-bold text-white"
                            >
                                {{ notifications.unread }}
                            </span>
                        </router-link>
                    </nav>

                    <!-- Drawer Footer -->
                    <div class="border-t border-ink-100 p-3 shrink-0">
                        <div class="mb-2 flex items-center gap-2.5 rounded-lg bg-ink-50 p-2.5">
                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-ink-900 text-[10px] font-bold text-white shrink-0">
                                {{ initials(auth.displayName) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-semibold text-ink-800" dir="auto">{{ auth.displayName }}</p>
                                <p class="truncate text-[10px] text-ink-500">{{ roleLabel }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-rose-600 transition hover:bg-rose-50"
                            @click="logout"
                        >
                            <Icon name="home" :size="18" />
                            <span>{{ $t('app.signout') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Mobile Bottom Navigation Bar (PWA friendly) -->
        <nav
            class="fixed bottom-0 inset-x-0 z-30 flex items-center justify-around border-t border-ink-200/80 bg-white/95 backdrop-blur-md px-1 py-1.5 pb-[max(0.35rem,env(safe-area-inset-bottom,0px))] lg:hidden shadow-lg"
            :aria-label="$t('app.menu')"
        >
            <router-link
                v-for="item in bottomNavItems"
                :key="item.to"
                :to="item.to"
                class="flex flex-1 flex-col items-center justify-center py-1 text-center transition"
                :class="isActive(item.to) ? 'text-terracotta-600 font-semibold' : 'text-ink-500 hover:text-ink-800'"
            >
                <div class="relative">
                    <Icon :name="item.icon" :size="20" />
                    <span
                        v-if="item.icon === 'bell' && notifications.unread"
                        class="absolute -end-1 -top-1 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-terracotta-600 px-0.5 text-[9px] font-bold text-white"
                    >
                        {{ notifications.unread }}
                    </span>
                </div>
                <span class="mt-1 truncate max-w-[64px] text-[10px] leading-none">{{ item.label }}</span>
            </router-link>

            <!-- 5th Tab: More / Drawer Toggle if there are extra items -->
            <button
                v-if="hasMoreInDrawer"
                type="button"
                class="flex flex-1 flex-col items-center justify-center py-1 text-center text-ink-500 hover:text-ink-800 transition"
                :class="mobileOpen ? 'text-terracotta-600 font-semibold' : ''"
                @click="mobileOpen = true"
            >
                <Icon name="moreHorizontal" :size="20" />
                <span class="mt-1 truncate max-w-[64px] text-[10px] leading-none">{{ $t('app.more') }}</span>
            </button>
        </nav>

        <!-- Main content -->
        <main class="min-h-[calc(100vh-4rem)] px-3 sm:px-4 py-6 pb-24 lg:pb-8 lg:ps-72 lg:pe-8">
            <div class="mx-auto max-w-5xl">
                <slot />
            </div>
        </main>
    </div>
</template>
