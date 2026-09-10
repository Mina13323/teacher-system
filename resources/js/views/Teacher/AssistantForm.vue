<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher } from '@/api';
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
    if (!isEdit) return;
    try {
        const a = await teacher.assistant(id);
        form.name = a.name;
        form.email = a.email;
        form.phone = a.phone || '';
        form.bio = a.bio || '';
    } catch (e) {
        toast.error(e.message);
    } finally {
        loading.value = false;
    }
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        if (isEdit) {
            await teacher.updateAssistant(id, { name: form.name, email: form.email, phone: form.phone || null, bio: form.bio || null });
            toast.success(t('assistants.updated'));
        } else {
            await teacher.createAssistant({ name: form.name, email: form.email, password: form.password, phone: form.phone || null, bio: form.bio || null });
            toast.success(t('assistants.created'));
        }
        router.push('/teacher/assistants');
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
        <router-link to="/teacher/assistants" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.assistants') }}</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ isEdit ? $t('assistants.editAssistant') : $t('assistants.addAssistant') }}</h1>

        <LoadingSpinner v-if="loading" />
        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard :title="$t('assistants.accountCard')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.name" :label="$t('common.fullName')" required id="as-name" :error="errors.name" />
                    <AppInput v-model="form.email" :label="$t('auth.email')" type="email" required id="as-email" :error="errors.email" autocomplete="email" />
                </div>
                <div class="mt-4"><AppInput v-model="form.phone" :label="$t('common.phone')" id="as-phone" :error="errors.phone" /></div>
                <div class="mt-4"><AppTextarea v-model="form.bio" :label="$t('common.bio')" id="as-bio" :error="errors.bio" :rows="2" /></div>
            </AppCard>

            <AppCard v-if="!isEdit" :title="$t('common.credentials')">
                <AppInput v-model="form.password" :label="$t('auth.password')" type="password" required id="as-password" :error="errors.password" autocomplete="new-password" :hint="$t('common.passwordMin')" />
            </AppCard>

            <div class="flex justify-end gap-2">
                <router-link to="/teacher/assistants"><AppButton variant="outline">{{ $t('common.cancel') }}</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ isEdit ? $t('common.saveChanges') : $t('assistants.createAssistant') }}</AppButton>
            </div>
        </form>
    </div>
</template>
