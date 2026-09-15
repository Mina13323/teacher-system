<script setup>
import { ref, onMounted } from 'vue';
import { usePwa } from '@/composables/usePwa';
import Icon from '@/components/ui/Icon.vue';
import AppButton from '@/components/ui/AppButton.vue';

const { isInstallable, isInstalled, isIos, promptInstall } = usePwa();
const isDismissed = ref(false);

onMounted(() => {
    if (sessionStorage.getItem('pwa_prompt_dismissed') === '1') {
        isDismissed.value = true;
    }
});

function dismiss() {
    isDismissed.value = true;
    sessionStorage.setItem('pwa_prompt_dismissed', '1');
}

async function handleInstall() {
    const installed = await promptInstall();
    if (installed) {
        dismiss();
    }
}
</script>

<template>
    <!-- Display only if not installed and not dismissed -->
    <div
        v-if="!isInstalled && !isDismissed && (isInstallable || isIos)"
        class="fixed bottom-5 start-5 z-50 max-w-sm rounded-2xl border border-ink-200 bg-white p-4 shadow-2xl transition-all duration-300 animate-in fade-in slide-in-from-bottom-4"
    >
        <div class="flex items-start gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-terracotta-600 text-white shadow-md">
                <Icon name="compass" :size="24" />
            </div>

            <div class="flex-1">
                <div class="flex items-center justify-between">
                    <h4 class="font-display text-sm font-bold text-ink-900">
                        {{ $t('pwa.installTitle') }}
                    </h4>
                    <button
                        type="button"
                        @click="dismiss"
                        class="rounded p-1 text-ink-400 hover:bg-ink-100 hover:text-ink-600"
                        :aria-label="$t('pwa.dismissInstall')"
                    >
                        <Icon name="x" :size="16" />
                    </button>
                </div>

                <p class="mt-1 text-xs text-ink-600">
                    {{ $t('pwa.installBody') }}
                </p>

                <!-- iOS Safari specific Share Hint -->
                <div v-if="isIos" class="mt-2.5 rounded-lg border border-amber-200 bg-amber-50 p-2 text-[11px] text-amber-800">
                    {{ $t('pwa.installIosPrefix') }}
                    <span class="font-bold">{{ $t('pwa.installIosShare') }}</span>
                    {{ $t('pwa.installIosArrow') }}
                    <span class="font-bold">{{ $t('pwa.installIosAdd') }}</span>.
                </div>

                <div v-else class="mt-3 flex items-center gap-2">
                    <AppButton size="sm" variant="primary" @click="handleInstall">
                        <Icon name="sparkles" :size="14" class="me-1" />
                        {{ $t('pwa.installCta') }}
                    </AppButton>
                    <button type="button" @click="dismiss" class="px-2 py-1 text-xs font-medium text-ink-500 hover:text-ink-800">
                        {{ $t('pwa.installLater') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
