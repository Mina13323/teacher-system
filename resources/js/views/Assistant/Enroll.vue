<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { teacher, publicCatalog, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';

const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const loading = ref(true);
const error = ref('');
const courses = ref([]);
const students = ref([]);
const selectedCourse = ref('');
const selectedStudent = ref('');
const enrolling = ref(false);
const errors = ref({});

async function load() {
    loading.value = true;
    try {
        const [c, s] = await Promise.all([
            publicCatalog.courses({ per_page: 100 }),
            teacher.students({ per_page: 100 }),
        ]);
        courses.value = toList(c).items.map((x) => ({ value: x.id, label: x.title }));
        students.value = toList(s).items.filter((x) => x.is_active);
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function submit() {
    enrolling.value = true;
    errors.value = {};
    try {
        await teacher.enrollStudent(selectedCourse.value, selectedStudent.value);
        toast.success('Student enrolled.');
        router.push(`/assistant/courses/${selectedCourse.value}/students`);
    } catch (e) {
        errors.value = fieldErrors(e);
        toast.error(e.isValidation ? 'Please fix the highlighted fields.' : e.message);
    } finally {
        enrolling.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <router-link to="/assistant/students" class="text-sm font-medium text-terracotta-600 hover:underline">← Back to students</router-link>
            <h1 class="mt-2 text-2xl font-bold text-ink-900">Enroll a student</h1>
            <p class="text-ink-500">Choose a published course and a student to enroll.</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard title="Enrollment">
                <div class="space-y-4">
                    <AppSelect v-model="selectedCourse" label="Course" :options="courses" id="enroll-course" placeholder="Select a course" :error="errors.course_id" />
                    <AppSelect v-model="selectedStudent" label="Student" :options="students.map((s) => ({ value: s.id, label: s.name }))" id="enroll-student" placeholder="Select a student" :error="errors.student_id" />
                    <p v-if="!students.length" class="text-sm text-amber-700">No active students available. <router-link to="/assistant/students/new" class="text-terracotta-600 hover:underline">Create a student</router-link> first.</p>
                </div>
            </AppCard>
            <div class="flex justify-end gap-2">
                <AppButton type="button" variant="outline" @click="router.push('/assistant/students')">Cancel</AppButton>
                <AppButton type="submit" :loading="enrolling" :disabled="!selectedCourse || !selectedStudent">Enroll</AppButton>
            </div>
        </form>
    </div>
</template>
