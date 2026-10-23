<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/stores/auth';
import { profilePathFor } from '@/utils/authRoutes';
import { formatDateTime } from '@/utils/format';
import { useFieldErrors } from '@/composables/fieldErrors';
import AppInput from '@/components/ui/AppInput.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Alert from '@/components/ui/Alert.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const { t } = useI18n();
const { fieldErrors } = useFieldErrors();

const form = reactive({ email: '', password: '' });
const errors = reactive({});
const errorMsg = ref('');
/** Set when the password was right but the account is signed in on another device. */
const transfer = ref(null);

const endedNotice = computed(() => ({
    session_transferred: t('auth.sessionTransferredNotice'),
    session_ended: t('auth.sessionEndedNotice'),
}[auth.endedReason] || ''));

function homeFor(roles) {
    if (roles.includes('admin')) return '/admin';
    if (roles.includes('teacher')) return '/teacher';
    if (roles.includes('assistant')) return '/assistant';
    return '/student';
}

function goHome() {
    const target = auth.user?.must_change_password
        ? profilePathFor(auth.roles)
        : (route.query.redirect || homeFor(auth.roles));
    router.push(target);
}

async function submit() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    errorMsg.value = '';
    transfer.value = null;
    try {
        await auth.login(form);
        goHome();
    } catch (e) {
        if (e?.status === 409 && e?.data?.code === 'session_transfer_required') {
            const other = e.data.data?.other_session || {};
            transfer.value = {
                token: e.data.data?.transfer_token,
                device: other.device || '',
                lastActive: other.last_active_at ? formatDateTime(other.last_active_at) : '',
            };
            return;
        }
        Object.assign(errors, fieldErrors(e));
        errorMsg.value = e.isValidation ? '' : e.message;
    }
}

/** The student chose to move the account here; the other device is signed out. */
async function confirmTransfer() {
    const token = transfer.value?.token;
    if (!token) return;
    errorMsg.value = '';
    try {
        await auth.transferSession(token);
        transfer.value = null;
        form.password = '';
        goHome();
    } catch (e) {
        // Expired or already used: sign in again from the start.
        transfer.value = null;
        errorMsg.value = e.message;
    }
}

function cancelTransfer() {
    // Nothing was changed on the server; the other device stays signed in.
    transfer.value = null;
    form.password = '';
}
</script>

<template>
    <div class="grid min-h-screen bg-parchment-50 lg:grid-cols-2">
        <!-- Brand panel -->
        <div class="relative hidden overflow-hidden bg-ink-900 text-white lg:flex lg:flex-col lg:justify-between lg:p-12">
            <div class="absolute inset-0 opacity-20" style="background: radial-gradient(60% 60% at 80% 10%, #d95a2b33, transparent), radial-gradient(50% 50% at 10% 90%, #7f93ad33, transparent);" />
            <div class="relative">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-terracotta-600 text-white">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36 6.36-2.12Z"/></svg>
                    </div>
                    <span class="font-display text-xl font-semibold" dir="auto">{{ $t('app.brand') }}</span>
                </div>
                <h1 class="mt-10 font-display text-4xl font-bold leading-tight" dir="auto">{{ $t('auth.loginHero') }}</h1>
                <p class="mt-4 max-w-md text-white/70" dir="auto">{{ $t('auth.loginHeroSub') }}</p>
            </div>
            <div class="relative flex items-end gap-6 text-sm text-white/60">
                <span>{{ $t('app.tagline') }}</span><span class="h-1 w-1 rounded-full bg-white/40" /><span>{{ $t('app.tagline') }}</span>
            </div>
        </div>

        <!-- Form panel -->
        <div class="flex items-center justify-center px-4 py-12">
            <div class="w-full max-w-md">
                <div class="mb-8 flex items-center justify-between">
                    <div class="flex items-center gap-2 lg:hidden">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-white">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36 6.36-2.12Z"/></svg>
                        </div>
                        <span class="font-display text-lg font-semibold text-ink-900" dir="auto">{{ $t('app.brand') }}</span>
                    </div>
                    <LanguageSwitcher />
                </div>
                <h2 class="text-2xl font-bold text-ink-900">{{ $t('auth.loginTitle') }}</h2>
                <p class="mt-1 text-sm text-ink-500">{{ $t('auth.loginSubtitle') }}</p>

                <Alert v-if="endedNotice && !errorMsg && !transfer" tone="warning" class="mt-4" role="status">{{ endedNotice }}</Alert>
                <Alert v-if="errorMsg" tone="danger" class="mt-4">{{ errorMsg }}</Alert>

                <section v-if="transfer" class="mt-6 space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950" role="alertdialog" aria-labelledby="transfer-title" aria-describedby="transfer-body">
                    <h3 id="transfer-title" class="font-semibold">{{ $t('auth.transferTitle') }}</h3>
                    <p id="transfer-body" class="text-sm" dir="auto">{{ $t('auth.transferBody', { device: transfer.device, time: transfer.lastActive }) }}</p>
                    <p class="text-sm" dir="auto">{{ $t('auth.transferExam') }}</p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <AppButton :loading="auth.loading" class="w-full sm:w-auto" @click="confirmTransfer">{{ $t('auth.transferConfirm') }}</AppButton>
                        <AppButton variant="outline" class="w-full sm:w-auto" :disabled="auth.loading" @click="cancelTransfer">{{ $t('auth.transferCancel') }}</AppButton>
                    </div>
                </section>

                <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
                    <AppInput v-model="form.email" :label="$t('auth.email')" type="email" id="login-email" required autocomplete="email" :error="errors.email" placeholder="you@example.com" />
                    <AppInput v-model="form.password" :label="$t('auth.password')" type="password" id="login-password" required autocomplete="current-password" :error="errors.password" placeholder="••••••••" />
                    <AppButton type="submit" :loading="auth.loading" class="w-full" size="lg">{{ $t('auth.signIn') }}</AppButton>
                    <p class="text-center text-sm">
                        <router-link to="/forgot-password" class="font-medium text-terracotta-700 hover:underline">{{ $t('auth.forgot') }}</router-link>
                    </p>
                </form>
                <p class="mt-6 text-center text-sm text-ink-500" dir="auto">
                    {{ $t('auth.noAccountNote') }}
                </p>
            </div>
        </div>
    </div>
</template>
