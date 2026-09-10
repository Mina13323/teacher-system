<script setup>
import { useToast } from '@/composables/toast';

const { state, dismiss } = useToast();

const tones = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-rose-200 bg-rose-50 text-rose-800',
    info: 'border-sky-200 bg-sky-50 text-sky-800',
};
</script>

<template>
    <div class="fixed bottom-4 end-4 z-[70] flex w-full max-w-sm flex-col gap-2" role="status" aria-live="polite">
        <TransitionGroup name="toast">
            <div
                v-for="item in state.items"
                :key="item.id"
                class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg"
                :class="tones[item.type] || tones.info"
            >
                <p class="flex-1">{{ item.message }}</p>
                <button type="button" class="shrink-0 rounded p-0.5 hover:bg-black/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-current" :aria-label="$t('app.close')" @click="dismiss(item.id)">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active, .toast-leave-active { transition: all 0.25s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(8px); }
</style>
