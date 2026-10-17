<script setup>
import { ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import AppButton from '@/components/ui/AppButton.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

/**
 * Shown when the stored sign-in could not be checked because the server was
 * unreachable or briefly failing. The token is kept: only a 401 signs out.
 */
const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const retrying = ref(false);
const stillFailing = ref(false);

function homeFor(roles) {
    if (roles.includes('admin')) return '/admin';
    if (roles.includes('teacher')) return '/teacher';
    if (roles.includes('assistant')) return '/assistant';
    return '/student';
}

function safeRedirect() {
    const target = route.query.redirect;
    // Internal paths only, so the query string cannot send the user offsite.
    return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') ? target : null;
}

async function retry() {
    retrying.value = true;
    stillFailing.value = false;
    try {
        await auth.fetchMe();
        router.replace(safeRedirect() || homeFor(auth.roles));
    } catch {
        if (!auth.isAuthenticated) {
            router.replace({ name: 'login' });
            return;
        }
        stillFailing.value = true;
    } finally {
        retrying.value = false;
    }
}

async function signOut() {
    await auth.logout();
    router.replace({ name: 'login' });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-parchment-50 px-4 py-12">
        <div class="w-full max-w-md text-center">
            <div class="mb-6 flex justify-end">
                <LanguageSwitcher />
            </div>
            <h1 class="text-2xl font-bold text-ink-900" dir="auto">{{ $t('auth.reconnectTitle') }}</h1>
            <p class="mt-2 text-sm text-ink-500" dir="auto">{{ $t('auth.reconnectMessage') }}</p>
            <p v-if="stillFailing" class="mt-3 text-sm text-terracotta-700" dir="auto">{{ $t('auth.reconnectStillFailing') }}</p>
            <div class="mt-6 space-y-3">
                <AppButton class="w-full" size="lg" :loading="retrying" @click="retry">{{ $t('auth.reconnectRetry') }}</AppButton>
                <button type="button" class="text-sm text-ink-500 underline" @click="signOut">{{ $t('auth.reconnectSignOut') }}</button>
            </div>
        </div>
    </div>
</template>
