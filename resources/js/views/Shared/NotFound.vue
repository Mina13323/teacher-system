<script setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();

onMounted(() => {
    if (auth.isAuthenticated) router.replace(`/${auth.role}`);
});
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-parchment-50 px-4 text-center">
        <p class="font-display text-7xl font-bold text-terracotta-600">404</p>
        <h1 class="mt-2 text-2xl font-bold text-ink-900">Page not found</h1>
        <p class="mt-2 max-w-sm text-ink-500">The page you're looking for doesn't exist or has moved.</p>
        <router-link to="/" class="mt-6 inline-flex items-center gap-2 rounded-lg bg-ink-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-ink-800">
            Go home
        </router-link>
    </div>
</template>
