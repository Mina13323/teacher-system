<script setup>
defineProps({
    title: { type: String, default: '' },
    message: { type: String, default: '' },
    icon: { type: String, default: 'compass' },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-ink-200 bg-parchment-50 px-6 py-14 text-center">
        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-parchment-200 text-ink-500">
            <svg v-if="icon === 'book'" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V4H6.5A2.5 2.5 0 0 0 4 6.5v13Z"/><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/></svg>
            <svg v-else-if="icon === 'users'" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <svg v-else-if="icon === 'clipboard'" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
            <svg v-else class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36 6.36-2.12Z"/></svg>
        </div>
        <h3 class="mt-4 text-base font-semibold text-ink-800">{{ title || $t('common.empty') }}</h3>
        <p v-if="message" class="mt-1 max-w-sm text-sm text-ink-500">{{ message }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
