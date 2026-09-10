<script setup>
import { computed } from 'vue';

const props = defineProps({
    tone: { type: String, default: 'info' },
    title: { type: String, default: '' },
    dismissible: { type: Boolean, default: false },
});
const emit = defineEmits(['dismiss']);

const tones = {
    info: 'bg-sky-50 border-sky-200 text-sky-800',
    warning: 'bg-amber-50 border-amber-200 text-amber-800',
    danger: 'bg-rose-50 border-rose-200 text-rose-800',
    success: 'bg-emerald-50 border-emerald-200 text-emerald-800',
};
const cls = computed(() => tones[props.tone] || tones.info);
</script>

<template>
    <div :class="cls" class="flex items-start gap-3 rounded-lg border px-4 py-3 text-sm" role="alert">
        <div class="flex-1">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div class="text-ink-700"><slot /></div>
        </div>
        <button
            v-if="dismissible"
            type="button"
            class="shrink-0 rounded p-0.5 hover:bg-white/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
            :aria-label="$t('common.dismiss')"
            @click="emit('dismiss')"
        >
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
        </button>
    </div>
</template>
