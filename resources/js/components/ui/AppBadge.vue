<script setup>
import { computed } from 'vue';

const props = defineProps({
    tone: { type: String, default: 'neutral' },
});

const tones = {
    neutral: 'bg-ink-100 text-ink-700',
    primary: 'bg-terracotta-100 text-terracotta-700',
    success: 'bg-emerald-50 text-emerald-700',
    warning: 'bg-amber-50 text-amber-700',
    danger: 'bg-rose-50 text-rose-700',
    info: 'bg-sky-50 text-sky-700',
    purple: 'bg-violet-50 text-violet-700',
};
const cls = computed(() => tones[props.tone] || tones.neutral);
</script>

<template>
    <span :class="cls" class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium">
        <slot />
    </span>
</template>
