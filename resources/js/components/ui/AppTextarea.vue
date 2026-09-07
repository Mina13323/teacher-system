<script setup>
defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    modelValue: { type: String, default: '' },
    rows: { type: Number, default: 4 },
    placeholder: { type: String, default: '' },
    required: { type: Boolean, default: false },
    id: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <label v-if="label" :for="id || undefined" class="mb-1.5 block text-sm font-medium text-ink-800">
            {{ label }}<span v-if="required" class="text-terracotta-600"> *</span>
        </label>
        <textarea
            :id="id || undefined"
            :value="modelValue"
            :rows="rows"
            :required="required"
            :placeholder="placeholder"
            :aria-invalid="Boolean(error)"
            class="w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-ink-900 shadow-sm transition focus:outline-none focus:ring-2"
            :class="error ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-200' : 'border-ink-200 focus:border-terracotta-500 focus:ring-terracotta-200'"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <p v-if="hint && !error" class="mt-1 text-xs text-ink-400">{{ hint }}</p>
        <p v-if="error" :id="`${id || label}-error`" class="mt-1 text-xs font-medium text-rose-600">{{ error }}</p>
    </div>
</template>
