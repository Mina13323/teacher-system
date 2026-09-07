<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppButton from '@/components/ui/AppButton.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import Alert from '@/components/ui/Alert.vue';

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
        toast.success('Profile updated.');
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
        toast.success('Password changed.');
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
            <h1 class="text-2xl font-bold text-ink-900">Profile</h1>
            <p class="text-sm text-ink-500">Manage your personal information and account security.</p>
        </div>

        <Alert v-if="errorMsg" tone="danger" dismissible @dismiss="errorMsg = ''">{{ errorMsg }}</Alert>

        <LoadingSpinner v-if="loading" />

        <template v-else>
            <AppCard title="Personal information">
                <form class="space-y-4" @submit.prevent="save">
                    <AppInput v-model="form.name" label="Full name" required id="profile-name" :error="errors.name" />
                    <AppInput v-model="form.email" label="Email" :model-value="profile?.email" id="profile-email" readonly hint="Email can only be changed by a manager." />
                    <AppInput v-model="form.phone" label="Phone" id="profile-phone" :error="errors.phone" />
                    <AppInput v-model="form.avatar" label="Avatar URL" id="profile-avatar" :error="errors.avatar" placeholder="https://…" />
                    <AppTextarea v-model="form.bio" label="Bio" id="profile-bio" :error="errors.bio" :rows="3" />
                    <div class="flex justify-end">
                        <AppButton type="submit" :loading="saving">Save changes</AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard title="Security">
                <form class="space-y-4" @submit.prevent="savePassword">
                    <Alert v-if="passwordErrors" tone="danger">{{ passwordErrors.password }}</Alert>
                    <AppInput v-model="pw.current_password" label="Current password" type="password" id="pw-current" required :error="passwordErrors.current_password" autocomplete="current-password" />
                    <AppInput v-model="pw.password" label="New password" type="password" id="pw-new" required :error="passwordErrors.password" autocomplete="new-password" hint="At least 8 characters." />
                    <AppInput v-model="pw.password_confirmation" label="Confirm new password" type="password" id="pw-confirm" required :error="passwordErrors.password_confirmation" autocomplete="new-password" />
                    <div class="flex justify-end">
                        <AppButton type="submit" :loading="passwordSaving">Change password</AppButton>
                    </div>
                </form>
            </AppCard>
        </template>
    </div>
</template>
