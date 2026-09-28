<script setup>
import { ref } from 'vue';
import { usePwa } from '@/composables/usePwa';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';

const { needRefresh, updateServiceWorker } = usePwa();
const isUpdating = ref(false);

async function update() {
    if (isUpdating.value) return;
    isUpdating.value = true;

    try {
        // The PWA helper listens for controllerchange and normally reloads
        // automatically. Some mobile browsers do not dispatch that event to
        // an existing standalone window, so reload as a reliable fallback.
        await updateServiceWorker(true);
        window.setTimeout(() => window.location.reload(), 1200);
    } catch {
        // Keep the prompt visible and allow the user to try again.
        isUpdating.value = false;
    }
}
</script>

<template>
    <div
        v-if="needRefresh"
        class="fixed bottom-5 end-5 z-50 flex max-w-sm items-center gap-3 rounded-2xl border border-terracotta-200 bg-ink-900 p-4 text-white shadow-2xl transition-all duration-300 animate-in fade-in slide-in-from-bottom-4"
    >
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-terracotta-600 text-white">
            <Icon name="sparkles" :size="20" />
        </div>

        <div class="flex-1">
            <h4 class="text-sm font-bold text-white">
                {{ $t('pwa.updateTitle') }}
            </h4>
            <p class="text-xs text-ink-300">
                {{ $t('pwa.updateBody') }}
            </p>
        </div>

        <AppButton size="sm" variant="primary" :loading="isUpdating" @click="update">
            {{ $t('pwa.updateCta') }}
        </AppButton>
    </div>
</template>
