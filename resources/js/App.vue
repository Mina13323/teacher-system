<script setup>
import ToastHost from '@/components/ui/ToastHost.vue';
import OfflineBanner from '@/components/pwa/OfflineBanner.vue';
import AppInstallPrompt from '@/components/pwa/AppInstallPrompt.vue';
import PwaUpdateToast from '@/components/pwa/PwaUpdateToast.vue';
</script>

<template>
    <div class="relative min-h-screen bg-parchment-50">
        <OfflineBanner />
        <router-view />
        <ToastHost />
        <AppInstallPrompt />
        <PwaUpdateToast />
    </div>
</template>
