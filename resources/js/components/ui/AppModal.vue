<script setup>
import { watch, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    size: { type: String, default: 'md' },
    closeOnBackdrop: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);

const sizes = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };

function onKey(e) {
    if (e.key === 'Escape' && props.open) emit('close');
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

watch(() => props.open, (v) => {
    document.body.style.overflow = v ? 'hidden' : '';
});
</script>

<template>
    <Transition name="modal">
        <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4" @click.self="closeOnBackdrop && emit('close')">
            <div class="absolute inset-0 bg-ink-900/50 backdrop-blur-sm" @click="closeOnBackdrop && emit('close')" />
            <div
                class="relative z-10 w-full rounded-t-2xl bg-white shadow-xl sm:rounded-2xl"
                :class="sizes[size]"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
            >
                <div class="flex items-center justify-between border-b border-ink-100 px-5 py-4">
                    <h3 class="text-base font-semibold text-ink-900">{{ title }}</h3>
                    <button type="button" class="rounded p-1 text-ink-500 hover:bg-ink-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400" aria-label="Close" @click="emit('close')">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>
                <div class="max-h-[75vh] overflow-y-auto px-5 py-5"><slot /></div>
                <div v-if="$slots.footer" class="flex justify-end gap-2 border-t border-ink-100 px-5 py-3"><slot name="footer" /></div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.modal-enter-active, .modal-leave-active { transition: opacity 0.2s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
</style>
