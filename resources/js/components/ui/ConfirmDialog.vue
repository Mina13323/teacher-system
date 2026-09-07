<script setup>
import AppModal from './AppModal.vue';
import AppButton from './AppButton.vue';

defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Are you sure?' },
    message: { type: String, default: '' },
    confirmText: { type: String, default: 'Confirm' },
    tone: { type: String, default: 'danger' },
    loading: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'confirm']);
</script>

<template>
    <AppModal :open="open" :title="title" size="sm" @close="emit('close')">
        <p class="text-sm text-ink-600">{{ message }}</p>
        <template #footer>
            <AppButton variant="outline" :disabled="loading" @click="emit('close')">Cancel</AppButton>
            <AppButton :variant="tone" :loading="loading" @click="emit('confirm')">{{ confirmText }}</AppButton>
        </template>
    </AppModal>
</template>
