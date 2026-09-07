<script setup>
defineProps({
    label: { type: String, default: 'Loading…' },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-3 py-16 text-ink-400" role="status" aria-live="polite">
        <svg class="h-8 w-8 animate-spin text-terracotta-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/>
        </svg>
        <span class="text-sm">{{ label }}</span>
    </div>
</template>
