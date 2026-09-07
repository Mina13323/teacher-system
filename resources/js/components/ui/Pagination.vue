<script setup>
import { computed } from 'vue';

const props = defineProps({
    meta: { type: Object, default: null },
});
const emit = defineEmits(['change']);

const pages = computed(() => {
    if (!props.meta) return [];
    const { current_page, last_page } = props.meta;
    const total = last_page || 1;
    const start = Math.max(1, current_page - 2);
    const end = Math.min(total, current_page + 2);
    const arr = [];
    for (let i = start; i <= end; i++) arr.push(i);
    return arr;
});

function go(page) {
    if (!props.meta || page < 1 || page > (props.meta.last_page || 1) || page === props.meta.current_page) return;
    emit('change', page);
}
</script>

<template>
    <nav v-if="meta && (meta.last_page || 0) > 1" class="flex items-center justify-between gap-2 pt-4" aria-label="Pagination">
        <button
            class="rounded-lg border border-ink-200 px-3 py-1.5 text-sm font-medium text-ink-700 hover:bg-ink-50 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400"
            :disabled="meta.current_page <= 1"
            @click="go(meta.current_page - 1)"
        >
            Previous
        </button>
        <div class="flex items-center gap-1">
            <button
                v-for="p in pages"
                :key="p"
                class="h-9 w-9 rounded-lg text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400"
                :class="p === meta.current_page ? 'bg-terracotta-600 text-white' : 'text-ink-700 hover:bg-ink-100'"
                @click="go(p)"
            >
                {{ p }}
            </button>
        </div>
        <button
            class="rounded-lg border border-ink-200 px-3 py-1.5 text-sm font-medium text-ink-700 hover:bg-ink-50 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400"
            :disabled="meta.current_page >= (meta.last_page || 1)"
            @click="go(meta.current_page + 1)"
        >
            Next
        </button>
    </nav>
</template>
