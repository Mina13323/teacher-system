<script setup>
defineProps({
    label: { type: String, default: '' },
    value: { type: [String, Number], default: '' },
    icon: { type: String, default: 'chart' },
    tone: { type: String, default: 'ink' },
    hint: { type: String, default: '' },
});

const tones = {
    ink: 'bg-ink-900 text-white',
    terracotta: 'bg-terracotta-600 text-white',
    emerald: 'bg-emerald-600 text-white',
    amber: 'bg-amber-500 text-white',
    sky: 'bg-sky-600 text-white',
};
</script>

<template>
    <div class="rounded-xl border border-ink-100 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-ink-500">{{ label }}</p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-ink-900">{{ value }}</p>
                <p v-if="hint" class="mt-1 text-xs text-ink-400">{{ hint }}</p>
            </div>
            <div :class="tones[tone]" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg">
                <svg v-if="icon === 'users'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <svg v-else-if="icon === 'book'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V4H6.5A2.5 2.5 0 0 0 4 6.5v13Z"/><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/></svg>
                <svg v-else-if="icon === 'trophy'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                <svg v-else-if="icon === 'clipboard'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                <svg v-else-if="icon === 'chart'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                <svg v-else-if="icon === 'shield'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></svg>
                <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/></svg>
            </div>
        </div>
    </div>
</template>
