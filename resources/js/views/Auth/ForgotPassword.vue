<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { profilePathFor } from '@/utils/authRoutes';
import { useFieldErrors } from '@/composables/fieldErrors';
import AppInput from '@/components/ui/AppInput.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Alert from '@/components/ui/Alert.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

/**
 * Forgotten password. The student code, the phone number on the account and
 * the saved recovery code must all match; the server then signs the student in
 * for the forced "choose a new password" step. Anything else (even a wrong
 * detail) is answered with the same "contact the admin" message.
 */
const router = useRouter();
const auth = useAuthStore();
const { fieldErrors } = useFieldErrors();

const form = reactive({ student_code: '', phone: '', recovery_code: '' });
const errors = reactive({});
const errorMsg = ref('');

async function submit() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    errorMsg.value = '';
    try {
        await auth.recover(form);
        router.push(profilePathFor(auth.roles));
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        errorMsg.value = e.isValidation ? '' : e.message;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-parchment-50 px-4 py-12">
        <div class="w-full max-w-md">
            <div class="mb-8 flex items-center justify-between">
                <span class="font-display text-lg font-semibold text-ink-900" dir="auto">{{ $t('app.brand') }}</span>
                <LanguageSwitcher />
            </div>

            <h1 class="text-2xl font-bold text-ink-900">{{ $t('auth.recoverTitle') }}</h1>
            <p class="mt-1 text-sm text-ink-500" dir="auto">{{ $t('auth.recoverSubtitle') }}</p>

            <Alert v-if="errorMsg" tone="danger" class="mt-4">{{ errorMsg }}</Alert>

            <form class="mt-6 space-y-4" @submit.prevent="submit">
                <AppInput v-model="form.student_code" :label="$t('auth.recoverStudentCode')" id="recover-code" required autocomplete="username" :error="errors.student_code" />
                <AppInput v-model="form.phone" :label="$t('auth.recoverPhone')" id="recover-phone" type="tel" required autocomplete="tel" :error="errors.phone" />
                <AppInput v-model="form.recovery_code" :label="$t('auth.recoverCode')" id="recover-recovery" required autocomplete="off" placeholder="XXXX-XXXX-XXXX" :error="errors.recovery_code" />
                <AppButton type="submit" :loading="auth.loading" class="w-full" size="lg">{{ $t('auth.recoverSubmit') }}</AppButton>
            </form>

            <p class="mt-4 text-sm text-ink-500" dir="auto">{{ $t('auth.recoverHelp') }}</p>
            <p class="mt-4 text-center text-sm">
                <router-link to="/login" class="font-medium text-terracotta-700 hover:underline">{{ $t('auth.recoverBack') }}</router-link>
            </p>
        </div>
    </div>
</template>
