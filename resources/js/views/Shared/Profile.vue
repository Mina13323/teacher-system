<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppButton from '@/components/ui/AppButton.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import Alert from '@/components/ui/Alert.vue';

const { t } = useI18n();
const auth = useAuthStore();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const loading = ref(true);
const saving = ref(false);
const errors = reactive({});
const passwordErrors = reactive({});
const passwordSaving = ref(false);
const errorMsg = ref('');

const form = reactive({
    name: '',
    avatar: '',
    phone: '',
    bio: '',
});

const profile = ref(null);

async function load() {
    loading.value = true;
    errorMsg.value = '';
    try {
        const p = await auth.loadProfile();
        profile.value = p;
        form.name = p.name || '';
        form.avatar = p.avatar || '';
        form.phone = p.phone || '';
        form.bio = p.bio || '';
    } catch (e) {
        errorMsg.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    errorMsg.value = '';
    try {
        await auth.updateProfile({
            name: form.name,
            avatar: form.avatar || null,
            phone: form.phone || null,
            bio: form.bio || null,
        });
        toast.success(t('profile.updated'));
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        errorMsg.value = e.isValidation ? '' : e.message;
    } finally {
        saving.value = false;
    }
}

const pw = reactive({ current_password: '', password: '', password_confirmation: '' });

async function savePassword() {
    passwordSaving.value = true;
    Object.keys(passwordErrors).forEach((k) => delete passwordErrors[k]);
    errorMsg.value = '';
    try {
        await auth.changePassword(pw);
        pw.current_password = '';
        pw.password = '';
        pw.password_confirmation = '';
        toast.success(t('profile.passwordChanged'));
    } catch (e) {
        Object.assign(passwordErrors, fieldErrors(e));
        errorMsg.value = e.isValidation ? '' : e.message;
    } finally {
        passwordSaving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-ink-900">{{ $t('profile.title') }}</h1>
            <p class="text-sm text-ink-500">{{ $t('profile.subtitle') }}</p>
        </div>

        <Alert v-if="errorMsg" tone="danger" dismissible @dismiss="errorMsg = ''">{{ errorMsg }}</Alert>

        <LoadingSpinner v-if="loading" />

        <template v-else>
            <AppCard :title="$t('profile.personalInfo')">
                <form class="space-y-4" @submit.prevent="save">
                    <AppInput v-model="form.name" :label="$t('profile.fullName')" required id="profile-name" :error="errors.name" />
                    <AppInput v-model="form.email" :label="$t('profile.email')" :model-value="profile?.email" id="profile-email" readonly :hint="$t('profile.emailHint')" />
                    <AppInput v-model="form.phone" :label="$t('profile.phone')" id="profile-phone" :error="errors.phone" />
                    <AppInput v-model="form.avatar" :label="$t('profile.avatar')" id="profile-avatar" :error="errors.avatar" placeholder="https://…" />
                    <AppTextarea v-model="form.bio" :label="$t('profile.bio')" id="profile-bio" :error="errors.bio" :rows="3" />
                    <div class="flex justify-end">
                        <AppButton type="submit" :loading="saving">{{ $t('common.saveChanges') }}</AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard :title="$t('profile.security')">
                <form class="space-y-4" @submit.prevent="savePassword">
                    <Alert v-if="passwordErrors" tone="danger">{{ passwordErrors.password }}</Alert>
                    <AppInput v-model="pw.current_password" :label="$t('profile.currentPassword')" type="password" id="pw-current" required :error="passwordErrors.current_password" autocomplete="current-password" />
                    <AppInput v-model="pw.password" :label="$t('profile.newPassword')" type="password" id="pw-new" required :error="passwordErrors.password" autocomplete="new-password" :hint="$t('profile.passwordHint')" />
                    <AppInput v-model="pw.password_confirmation" :label="$t('profile.confirmPassword')" type="password" id="pw-confirm" required :error="passwordErrors.password_confirmation" autocomplete="new-password" />
                    <div class="flex justify-end">
                        <AppButton type="submit" :loading="passwordSaving">{{ $t('profile.changePassword') }}</AppButton>
                    </div>
                </form>
            </AppCard>
        </template>
    </div>
</template>
