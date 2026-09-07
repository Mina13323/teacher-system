<script setup>
import { reactive, ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useFieldErrors } from '@/composables/fieldErrors';
import AppInput from '@/components/ui/AppInput.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Alert from '@/components/ui/Alert.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const { fieldErrors } = useFieldErrors();

const form = reactive({ email: '', password: '' });
const errors = reactive({});
const errorMsg = ref('');

function homeFor(roles) {
    if (roles.includes('admin')) return '/admin';
    if (roles.includes('teacher')) return '/teacher';
    if (roles.includes('assistant')) return '/assistant';
    return '/student';
}

async function submit() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    errorMsg.value = '';
    try {
        await auth.login(form);
        const target = route.query.redirect || homeFor(auth.roles);
        router.push(target);
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        errorMsg.value = e.isValidation ? '' : e.message;
    }
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

                <Alert v-if="errorMsg" tone="danger" class="mt-4">{{ errorMsg }}</Alert>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <AppInput v-model="form.email" :label="$t('auth.email')" type="email" id="login-email" required autocomplete="email" :error="errors.email" placeholder="you@example.com" />
                    <AppInput v-model="form.password" :label="$t('auth.password')" type="password" id="login-password" required autocomplete="current-password" :error="errors.password" placeholder="••••••••" />
                    <AppButton type="submit" :loading="auth.loading" class="w-full" size="lg">{{ $t('auth.signIn') }}</AppButton>
                </form>
                <p class="mt-6 text-center text-sm text-ink-500" dir="auto">
                    {{ $t('auth.noAccountNote') }}
                </p>
            </div>
        </div>
    </div>
</template>
