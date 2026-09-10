<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { admin } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppButton from '@/components/ui/AppButton.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const id = route.params.id;
const isEdit = computed(() => Boolean(id));
const form = reactive({ name: '', email: '', password: '', phone: '', bio: '' });
const errors = reactive({});
const loading = ref(isEdit);
const saving = ref(false);

async function load() {
    if (isEdit) {
        try {
            const tc = await admin.teacher(id);
            form.name = tc.name;
            form.email = tc.email;
            form.phone = tc.phone || '';
            form.bio = tc.bio || '';
        } catch (e) {
            toast.error(e.message);
        } finally {
            loading.value = false;
        }
    }
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        if (isEdit) {
            await admin.updateTeacher(id, {
                name: form.name,
                email: form.email,
                phone: form.phone || null,
                bio: form.bio || null,
            });
            toast.success(t('teachers.updated'));
        } else {
            await admin.createTeacher({
                name: form.name,
                email: form.email,
                password: form.password,
                phone: form.phone || null,
            });
            toast.success(t('teachers.created'));
        }
        router.push('/admin/teachers');
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        toast.error(e.isValidation ? t('common.fixFields') : e.message);
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <router-link to="/admin/teachers" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('teachers.title') }}</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ isEdit ? $t('teachers.editTeacher') : $t('teachers.addTeacher') }}</h1>

        <LoadingSpinner v-if="loading" />
        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard :title="$t('common.accountDetails')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.name" :label="$t('common.fullName')" required id="admin-teacher-name" :error="errors.name" />
                    <AppInput v-model="form.email" :label="$t('auth.email')" type="email" required id="admin-teacher-email" :error="errors.email" autocomplete="email" />
                    <AppInput v-model="form.phone" :label="$t('common.phone')" id="admin-teacher-phone" :error="errors.phone" />
                </div>
                <div class="mt-4"><AppTextarea v-model="form.bio" :label="$t('common.bio')" id="admin-teacher-bio" :error="errors.bio" :rows="2" /></div>
            </AppCard>

            <AppCard v-if="!isEdit" :title="$t('common.credentials')">
                <AppInput v-model="form.password" :label="$t('auth.password')" type="password" required id="admin-teacher-password" :error="errors.password" autocomplete="new-password" :hint="$t('common.passwordMin')" />
            </AppCard>

            <div class="flex justify-end gap-2">
                <router-link to="/admin/teachers"><AppButton variant="outline">{{ $t('common.cancel') }}</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ isEdit ? $t('common.saveChanges') : $t('teachers.createTeacher') }}</AppButton>
            </div>
        </form>
    </div>
</template>
