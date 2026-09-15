<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { usePwa } from '@/composables/usePwa';
import Icon from '@/components/ui/Icon.vue';

const { isOnline } = usePwa();
const showBackOnline = ref(false);

let timer = null;

watch(isOnline, (newVal, oldVal) => {
    if (newVal && !oldVal) {
        showBackOnline.value = true;
        // Clear any pending timer so rapid reconnects cannot stack timeouts.
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => {
            showBackOnline.value = false;
            timer = null;
        }, 3000);
    }
});

// `window` is not in Vue's template global allowlist, so the reload has to be a
// method on the instance rather than an inline `window.location.reload()`.
function reload() {
    window.location.reload();
}

onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
</script>

<template>
    <div>
        <!-- Offline Warning Bar -->
        <div
            v-if="!isOnline"
            class="sticky top-0 z-50 flex items-center justify-between gap-3 border-b border-amber-300/80 bg-amber-50 px-4 py-2 text-xs font-medium text-amber-900 shadow-sm"
        >
            <div class="flex items-center gap-2">
                <span class="flex h-2 w-2 shrink-0 rounded-full bg-amber-600 animate-ping"></span>
                <span>{{ $t('pwa.offline') }}</span>
            </div>
            <button
                type="button"
                @click="reload"
                class="shrink-0 rounded bg-amber-200 px-2 py-0.5 text-[11px] font-bold text-amber-950 hover:bg-amber-300"
            >
                {{ $t('pwa.retry') }}
            </button>
        </div>

        <!-- Back Online Toast -->
        <div
            v-if="showBackOnline"
            class="fixed top-4 end-4 z-50 flex items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-900 px-3.5 py-2 text-xs font-semibold text-white shadow-xl animate-in fade-in"
        >
            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
            <span>{{ $t('pwa.backOnline') }}</span>
        </div>
    </div>
</template>
