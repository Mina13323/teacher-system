<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
    required: { type: Boolean, default: false },
    id: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
const hasOptions = computed(() => props.options && props.options.length > 0);
const hasEmptyOption = computed(() => props.options?.some((opt) => opt.value === '' || opt.value === null));
const effectivePlaceholder = computed(() => {
    if (props.placeholder) return props.placeholder;
    if (hasEmptyOption.value) return '';
    return t('common.select') || 'Select';
});
</script>

<template>
    <div>
        <label v-if="label" :for="id || undefined" class="mb-1.5 block text-sm font-medium text-ink-800">
            {{ label }}<span v-if="required" class="text-terracotta-600"> *</span>
        </label>
        <select
            :id="id || undefined"
            :value="modelValue"
            :required="required"
            :disabled="disabled"
            :aria-invalid="Boolean(error)"
            class="w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-ink-900 shadow-sm transition focus:outline-none focus:ring-2 disabled:bg-ink-50 disabled:text-ink-500"
            :class="error ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-200' : 'border-ink-200 focus:border-terracotta-500 focus:ring-terracotta-200'"
            @change="emit('update:modelValue', $event.target.value)"
        >
            <option v-if="effectivePlaceholder" value="" disabled>{{ effectivePlaceholder }}</option>
            <option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
        <p v-if="hint && !error" class="mt-1 text-xs text-ink-400">{{ hint }}</p>
        <p v-if="error" :id="`${id || label}-error`" class="mt-1 text-xs font-medium text-rose-600">{{ error }}</p>
    </div>
</template>
