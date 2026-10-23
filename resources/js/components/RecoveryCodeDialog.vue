<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { student } from '@/api';
import { useAuthStore } from '@/stores/auth';
import AppModal from '@/components/ui/AppModal.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Alert from '@/components/ui/Alert.vue';

/**
 * Blocks the student portal until the student has seen their recovery code and
 * confirmed they saved it. The code comes from the server once; reloading
 * before confirming simply shows a fresh one.
 */
const { t } = useI18n();
const auth = useAuthStore();

const code = ref('');
const loading = ref(true);
const loadError = ref('');
const copied = ref(false);
const copyFailed = ref(false);
const saved = ref(false);
const confirming = ref(false);
const confirmError = ref('');

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const res = await student.issueRecoveryCode();
        code.value = res.recovery_code;
    } catch (e) {
        // Already saved elsewhere (another tab): nothing left to show.
        if (e?.status === 409) {
            auth.markRecoveryCodeSaved();
            return;
        }
        loadError.value = t('auth.recoveryDialogLoadFailed');
    } finally {
        loading.value = false;
    }
}

async function copy() {
    copyFailed.value = false;
    try {
        await navigator.clipboard.writeText(code.value);
        copied.value = true;
    } catch {
        copied.value = false;
        copyFailed.value = true;
    }
}

async function confirm() {
    confirming.value = true;
    confirmError.value = '';
    try {
        await student.confirmRecoveryCode();
        auth.markRecoveryCodeSaved();
    } catch (e) {
        confirmError.value = e.message;
    } finally {
        confirming.value = false;
    }
}

onMounted(load);
</script>

<template>
    <!-- Not dismissible: no backdrop click, and the close button does nothing. -->
    <AppModal :open="true" :title="$t('auth.recoveryDialogTitle')" :close-on-backdrop="false" @close="() => {}">
        <div class="space-y-4">
            <p class="text-sm text-ink-700" dir="auto">{{ $t('auth.recoveryDialogIntro') }}</p>

            <Alert v-if="loadError" tone="danger">{{ loadError }}</Alert>
            <Alert v-if="confirmError" tone="danger">{{ confirmError }}</Alert>

            <div v-if="loading" class="py-6 text-center text-sm text-ink-500">…</div>

            <template v-else-if="code">
                <div class="select-all rounded-xl border-2 border-dashed border-terracotta-300 bg-parchment-50 px-4 py-5 text-center font-mono text-2xl font-bold tracking-widest text-ink-900" dir="ltr" data-testid="recovery-code">
                    {{ code }}
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <AppButton variant="outline" @click="copy">{{ copied ? $t('auth.recoveryDialogCopied') : $t('auth.recoveryDialogCopy') }}</AppButton>
                    <span v-if="copyFailed" class="text-sm text-rose-700" dir="auto">{{ $t('auth.recoveryDialogCopyFailed') }}</span>
                </div>
                <Alert tone="warning">{{ $t('auth.recoveryDialogWarning') }}</Alert>
                <label class="flex cursor-pointer items-start gap-3 text-sm text-ink-800">
                    <input v-model="saved" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-ink-300 text-terracotta-600">
                    <span dir="auto">{{ $t('auth.recoveryDialogSaved') }}</span>
                </label>
            </template>
        </div>

        <template #footer>
            <AppButton v-if="loadError" @click="load">{{ $t('auth.recoveryDialogRetry') }}</AppButton>
            <AppButton v-else :disabled="!saved || !code" :loading="confirming" @click="confirm">{{ $t('auth.recoveryDialogContinue') }}</AppButton>
        </template>
    </AppModal>
</template>
