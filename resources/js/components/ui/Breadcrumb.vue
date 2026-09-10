<script setup>
defineProps({
    items: { type: Array, default: () => [] }, // [{ label, to? }]
});
</script>

<template>
    <nav :aria-label="$t('common.breadcrumb')" class="mb-4">
        <ol class="flex flex-wrap items-center gap-1.5 text-sm text-ink-500">
            <li v-for="(item, i) in items" :key="i" class="flex items-center gap-1.5">
                <router-link v-if="item.to" :to="item.to" class="font-medium hover:text-terracotta-600">{{ item.label }}</router-link>
                <span v-else class="font-medium text-ink-800">{{ item.label }}</span>
                <span v-if="i < items.length - 1" class="text-ink-300">/</span>
            </li>
        </ol>
    </nav>
</template>
