<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    type: { type: String, default: 'button' },
});

const classes = computed(() => {
    const base = 'inline-flex items-center justify-center gap-2 font-medium rounded-lg transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap';
    const sizes = {
        sm: 'text-sm px-3 py-1.5',
        md: 'text-sm px-4 py-2.5',
        lg: 'text-base px-5 py-3',
    };
    const variants = {
        primary: 'bg-terracotta-600 text-white hover:bg-terracotta-700 focus-visible:ring-terracotta-500 shadow-sm',
        secondary: 'bg-ink-900 text-white hover:bg-ink-800 focus-visible:ring-ink-700 shadow-sm',
        outline: 'border border-ink-300 text-ink-800 hover:bg-ink-50 focus-visible:ring-ink-400 bg-white',
        ghost: 'text-ink-700 hover:bg-ink-100 focus-visible:ring-ink-300',
        danger: 'bg-rose-600 text-white hover:bg-rose-700 focus-visible:ring-rose-500 shadow-sm',
        success: 'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-500 shadow-sm',
    };
    return [base, sizes[props.size], variants[props.variant]];
});
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading">
        <span v-if="loading" class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
        <slot v-else />
    </button>
</template>
