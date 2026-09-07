<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useFieldErrors } from '@/composables/fieldErrors';
import AppInput from '@/components/ui/AppInput.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Alert from '@/components/ui/Alert.vue';

const router = useRouter();
const auth = useAuthStore();
const { fieldErrors } = useFieldErrors();

const form = reactive({ name: '', email: '', password: '', password_confirmation: '' });
const errors = reactive({});
const errorMsg = ref('');

async function submit() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    errorMsg.value = '';
    try {
        await auth.register(form);
        router.push('/student');
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        errorMsg.value = e.isValidation ? '' : e.message;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-parchment-50 px-4 py-12">
        <div class="w-full max-w-md">
            <div class="mb-8 flex items-center gap-2">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36 6.36-2.12Z"/></svg>
                </div>
                <span class="font-display text-lg font-semibold text-ink-900">Atlas Academy</span>
            </div>
            <h2 class="text-2xl font-bold text-ink-900">Create your account</h2>
            <p class="mt-1 text-sm text-ink-500">Join Atlas Academy as a student.</p>

            <Alert v-if="errorMsg" tone="danger" class="mt-4">{{ errorMsg }}</Alert>

            <form class="mt-6 space-y-4" @submit.prevent="submit">
                <AppInput v-model="form.name" label="Full name" id="reg-name" required :error="errors.name" autocomplete="name" />
                <AppInput v-model="form.email" label="Email" type="email" id="reg-email" required :error="errors.email" autocomplete="email" placeholder="you@example.com" />
                <AppInput v-model="form.password" label="Password" type="password" id="reg-password" required :error="errors.password" autocomplete="new-password" hint="At least 8 characters." />
                <AppInput v-model="form.password_confirmation" label="Confirm password" type="password" id="reg-password-confirm" required :error="errors.password_confirmation" autocomplete="new-password" />
                <AppButton type="submit" :loading="auth.loading" class="w-full" size="lg">Create account</AppButton>
            </form>
            <p class="mt-6 text-center text-sm text-ink-500">
                Already have an account?
                <router-link to="/login" class="font-medium text-terracotta-600 hover:underline">Sign in</router-link>
            </p>
        </div>
    </div>
</template>
