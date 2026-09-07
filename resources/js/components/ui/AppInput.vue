<script setup>
defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    required: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    autocomplete: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    id: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'blur']);
</script>

<template>
    <div>
        <label v-if="label" :for="id || undefined" class="mb-1.5 block text-sm font-medium text-ink-800">
            {{ label }}<span v-if="required" class="text-terracotta-600"> *</span>
        </label>
        <input
            :id="id || undefined"
            :value="modelValue"
            :type="type"
            :required="required"
            :placeholder="placeholder"
            :autocomplete="autocomplete"
            :disabled="disabled"
            :readonly="readonly"
            :aria-invalid="Boolean(error)"
            :aria-describedby="error ? `${id || label}-error` : undefined"
            class="w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-ink-900 shadow-sm transition focus:outline-none focus:ring-2 disabled:bg-ink-50 disabled:text-ink-500"
            :class="error ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-200' : 'border-ink-200 focus:border-terracotta-500 focus:ring-terracotta-200'"
            @input="emit('update:modelValue', $event.target.value)"
            @blur="emit('blur')"
        />
        <p v-if="hint && !error" class="mt-1 text-xs text-ink-400">{{ hint }}</p>
        <p v-if="error" :id="`${id || label}-error`" class="mt-1 text-xs font-medium text-rose-600">{{ error }}</p>
    </div>
</template>
