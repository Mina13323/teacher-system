<script setup>
defineProps({
    rows: { type: Number, default: 3 },
    animated: { type: Boolean, default: true },
});
</script>

<template>
    <div class="space-y-3" aria-hidden="true">
        <div
            v-for="i in rows"
            :key="i"
            class="rounded-lg bg-ink-100"
            :class="[animated ? 'animate-pulse' : '']"
            :style="{ height: `${Math.min(24, 8 + i * 4)}px` }"
        />
    </div>
</template>
