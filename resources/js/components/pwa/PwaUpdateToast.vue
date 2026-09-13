<script setup>
import { usePwa } from '@/composables/usePwa';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';

const { needRefresh, updateServiceWorker } = usePwa();

function update() {
    updateServiceWorker(true);
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
                New Version Available
            </h4>
            <p class="text-xs text-ink-300">
                Update to get the latest features and performance enhancements.
            </p>
        </div>

        <AppButton size="sm" variant="primary" @click="update">
            Update App
        </AppButton>
    </div>
</template>
