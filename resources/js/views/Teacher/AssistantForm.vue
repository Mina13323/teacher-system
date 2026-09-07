<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { teacher } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppButton from '@/components/ui/AppButton.vue';

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
            toast.success('Assistant updated.');
        } else {
            await teacher.createAssistant({ name: form.name, email: form.email, password: form.password, phone: form.phone || null, bio: form.bio || null });
            toast.success('Assistant created.');
        }
        router.push('/teacher/assistants');
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        toast.error(e.isValidation ? 'Please fix the highlighted fields.' : e.message);
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <router-link to="/teacher/assistants" class="text-sm font-medium text-terracotta-600 hover:underline">← Assistants</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ isEdit ? 'Edit assistant' : 'Add assistant' }}</h1>

        <LoadingSpinner v-if="loading" />
        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard title="Assistant account">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.name" label="Full name" required id="as-name" :error="errors.name" />
                    <AppInput v-model="form.email" label="Email" type="email" required id="as-email" :error="errors.email" autocomplete="email" />
                </div>
                <div class="mt-4"><AppInput v-model="form.phone" label="Phone" id="as-phone" :error="errors.phone" /></div>
                <div class="mt-4"><AppTextarea v-model="form.bio" label="Bio" id="as-bio" :error="errors.bio" :rows="2" /></div>
            </AppCard>

            <AppCard v-if="!isEdit" title="Credentials">
                <AppInput v-model="form.password" label="Password" type="password" required id="as-password" :error="errors.password" autocomplete="new-password" hint="At least 8 characters." />
            </AppCard>

            <div class="flex justify-end gap-2">
                <router-link to="/teacher/assistants"><AppButton variant="outline">Cancel</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ isEdit ? 'Save changes' : 'Create assistant' }}</AppButton>
            </div>
        </form>
    </div>
</template>
