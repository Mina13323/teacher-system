<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { admin } from '@/api';
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
    if (isEdit) {
        try {
            const t = await admin.teacher(id);
            form.name = t.name;
            form.email = t.email;
            form.phone = t.phone || '';
            form.bio = t.bio || '';
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
            toast.success('Teacher updated.');
        } else {
            await admin.createTeacher({
                name: form.name,
                email: form.email,
                password: form.password,
                phone: form.phone || null,
            });
            toast.success('Teacher created.');
        }
        router.push('/admin/teachers');
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
        <router-link to="/admin/teachers" class="text-sm font-medium text-terracotta-600 hover:underline">← Back to teachers</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ isEdit ? 'Edit teacher' : 'Add teacher' }}</h1>

        <LoadingSpinner v-if="loading" />
        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard title="Account details">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.name" label="Full name" required id="admin-teacher-name" :error="errors.name" />
                    <AppInput v-model="form.email" label="Email" type="email" required id="admin-teacher-email" :error="errors.email" autocomplete="email" />
                    <AppInput v-model="form.phone" label="Phone" id="admin-teacher-phone" :error="errors.phone" />
                </div>
                <div class="mt-4"><AppTextarea v-model="form.bio" label="Bio" id="admin-teacher-bio" :error="errors.bio" :rows="2" /></div>
            </AppCard>

            <AppCard v-if="!isEdit" title="Credentials">
                <AppInput v-model="form.password" label="Password" type="password" required id="admin-teacher-password" :error="errors.password" autocomplete="new-password" hint="At least 8 characters." />
            </AppCard>

            <div class="flex justify-end gap-2">
                <router-link to="/admin/teachers"><AppButton variant="outline">Cancel</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ isEdit ? 'Save changes' : 'Create teacher' }}</AppButton>
            </div>
        </form>
    </div>
</template>
