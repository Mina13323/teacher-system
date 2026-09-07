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
import AppSelect from '@/components/ui/AppSelect.vue';
import AppButton from '@/components/ui/AppButton.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const id = route.params.id;
const isEdit = computed(() => Boolean(id));

const form = reactive({ title: '', slug: '', description: '', thumbnail: '', status: 'draft' });
const errors = reactive({});
const loading = ref(isEdit);
const saving = ref(false);

const statusOptions = [
    { value: 'draft', label: 'Draft' },
    { value: 'published', label: 'Published' },
    { value: 'archived', label: 'Archived' },
];

async function load() {
    if (!isEdit) return;
    try {
        const c = await teacher.course(id);
        form.title = c.title;
        form.slug = c.slug || '';
        form.description = c.description || '';
        form.thumbnail = c.thumbnail || '';
        form.status = c.status || 'draft';
    } catch (e) {
        toast.error(e.message);
    } finally {
        loading.value = false;
    }
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    const payload = { title: form.title, slug: form.slug || null, description: form.description || null, thumbnail: form.thumbnail || null, status: form.status };
    try {
        if (isEdit) {
            await teacher.updateCourse(id, payload);
            toast.success('Course updated.');
            router.push(`/teacher/courses/${id}`);
        } else {
            const created = await teacher.createCourse(payload);
            toast.success('Course created.');
            router.push(`/teacher/courses/${created.id}`);
        }
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
        <router-link :to="isEdit ? `/teacher/courses/${id}` : '/teacher/courses'" class="text-sm font-medium text-terracotta-600 hover:underline">← Courses</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ isEdit ? 'Edit course' : 'New course' }}</h1>

        <LoadingSpinner v-if="loading" />
        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard title="Course details">
                <div class="space-y-4">
                    <AppInput v-model="form.title" label="Title" required id="course-title" :error="errors.title" />
                    <AppInput v-model="form.slug" label="Slug" id="course-slug" :error="errors.slug" hint="Leave blank to auto-generate." />
                    <AppTextarea v-model="form.description" label="Description" id="course-desc" :error="errors.description" :rows="3" />
                    <AppInput v-model="form.thumbnail" label="Thumbnail URL" id="course-thumb" :error="errors.thumbnail" placeholder="https://…" />
                    <AppSelect v-model="form.status" label="Status" :options="statusOptions" id="course-status" :error="errors.status" />
                </div>
            </AppCard>
            <div class="flex justify-end gap-2">
                <router-link :to="isEdit ? `/teacher/courses/${id}` : '/teacher/courses'"><AppButton variant="outline">Cancel</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ isEdit ? 'Save changes' : 'Create course' }}</AppButton>
            </div>
        </form>
    </div>
</template>
