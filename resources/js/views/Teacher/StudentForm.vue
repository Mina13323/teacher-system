<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { teacher, toList } from '@/api';
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
const authRole = route.path.startsWith('/assistant') ? 'assistant' : 'teacher';

const form = reactive({ name: '', email: '', password: '', phone: '', bio: '', avatar: '', course_ids: [] });
const errors = reactive({});
const loading = ref(isEdit);
const saving = ref(false);
const courses = ref([]);

async function load() {
    if (isEdit) {
        const s = await teacher.student(id);
        form.name = s.name;
        form.email = s.email;
        form.phone = s.phone || '';
        form.bio = s.bio || '';
        form.avatar = s.avatar || '';
    }
    // For create, load courses to allow optional enrollment. Assistants do not
    // have course-list access (course structure is teacher-only), so this must
    // fail quietly and just leave the enrollment section hidden.
    try {
        const res = toList(await teacher.courses({ per_page: 100 }));
        courses.value = res.items.map((c) => ({ value: c.id, label: c.title }));
    } catch (e) {
        courses.value = [];
    }
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        if (isEdit) {
            await teacher.updateStudent(id, {
                name: form.name,
                email: form.email,
                phone: form.phone || null,
                bio: form.bio || null,
                avatar: form.avatar || null,
            });
            toast.success('Student updated.');
        } else {
            const payload = {
                name: form.name,
                email: form.email,
                password: form.password,
                phone: form.phone || null,
                bio: form.bio || null,
            };
            if (form.course_ids.length) payload.course_ids = form.course_ids;
            await teacher.createStudent(payload);
            toast.success('Student created.');
        }
        router.push(`/${authRole}/students`);
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        toast.error(e.isValidation ? 'Please fix the highlighted fields.' : e.message);
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        await load();
    } catch (e) {
        toast.error(e.message);
    } finally {
        loading.value = false;
    }
});

function toggleCourse(courseId) {
    const i = form.course_ids.indexOf(courseId);
    if (i === -1) form.course_ids.push(courseId);
    else form.course_ids.splice(i, 1);
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <router-link :to="`/${authRole}/students`" class="text-sm font-medium text-terracotta-600 hover:underline">← Back to students</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ isEdit ? 'Edit student' : 'Add student' }}</h1>

        <LoadingSpinner v-if="loading" />
        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard title="Student information">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.name" label="Full name" required id="student-name" :error="errors.name" />
                    <AppInput v-model="form.email" label="Email" type="email" required id="student-email" :error="errors.email" autocomplete="email" />
                    <AppInput v-model="form.phone" label="Phone" id="student-phone" :error="errors.phone" />
                    <AppInput v-model="form.avatar" label="Avatar URL" id="student-avatar" :error="errors.avatar" placeholder="https://…" />
                </div>
                <div class="mt-4"><AppTextarea v-model="form.bio" label="Bio" id="student-bio" :error="errors.bio" :rows="2" /></div>
            </AppCard>

            <AppCard v-if="!isEdit" title="Account credentials">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.password" label="Password" type="password" required id="student-password" :error="errors.password" autocomplete="new-password" hint="At least 8 characters." />
                </div>
            </AppCard>

            <AppCard v-if="!isEdit && courses.length" title="Optional: enroll in courses">
                <p class="mb-3 text-sm text-ink-500">Select courses to enroll this student in immediately.</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label v-for="c in courses" :key="c.value" class="flex items-center gap-2 rounded-lg border border-ink-200 px-3 py-2.5 text-sm hover:bg-ink-50">
                        <input type="checkbox" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" :checked="form.course_ids.includes(c.value)" @change="toggleCourse(c.value)" />
                        <span class="text-ink-800">{{ c.label }}</span>
                    </label>
                </div>
            </AppCard>

            <div class="flex justify-end gap-2">
                <router-link :to="`/${authRole}/students`"><AppButton variant="outline">Cancel</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ isEdit ? 'Save changes' : 'Create student' }}</AppButton>
            </div>
        </form>
    </div>
</template>
