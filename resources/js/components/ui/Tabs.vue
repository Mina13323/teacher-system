<script setup>
import { ref } from 'vue';

const props = defineProps({
    tabs: { type: Array, default: () => [] }, // [{ key, label }]
    modelValue: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const active = ref(props.modelValue || props.tabs[0]?.key || '');
function select(key) {
    active.value = key;
    emit('update:modelValue', key);
}
</script>

<template>
    <div class="border-b border-ink-100">
        <div class="flex gap-1 overflow-x-auto" role="tablist">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                role="tab"
                :aria-selected="active === tab.key"
                class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400"
                :class="active === tab.key ? 'border-terracotta-600 text-terracotta-700' : 'border-transparent text-ink-500 hover:text-ink-800'"
                @click="select(tab.key)"
            >
                {{ tab.label }}
            </button>
        </div>
    </div>
</template>
