<script setup>
defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    padded: { type: Boolean, default: true },
    hoverable: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="rounded-xl border border-ink-100 bg-white shadow-sm"
        :class="[hoverable ? 'transition hover:shadow-md hover:border-ink-200' : '', !padded && 'overflow-hidden']"
    >
        <div v-if="title || $slots.header" class="flex items-start justify-between gap-4 border-b border-ink-100 px-5 py-4">
            <div>
                <h3 v-if="title" class="text-base font-semibold text-ink-900">{{ title }}</h3>
                <p v-if="subtitle" class="mt-0.5 text-sm text-ink-500">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.header" class="flex items-center gap-2"><slot name="header" /></div>
        </div>
        <div :class="padded ? 'p-5' : ''">
            <slot />
        </div>
        <div v-if="$slots.footer" class="border-t border-ink-100 px-5 py-3"><slot name="footer" /></div>
    </div>
</template>
