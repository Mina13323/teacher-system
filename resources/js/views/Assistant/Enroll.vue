<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher, publicCatalog, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppButton from '@/components/ui/AppButton.vue';

const { t } = useI18n();
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
        toast.success(t('students.enrolled'));
        router.push(`/assistant/courses/${selectedCourse.value}/students`);
    } catch (e) {
        errors.value = fieldErrors(e);
        toast.error(e.isValidation ? t('common.fixFields') : e.message);
    } finally {
        enrolling.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <router-link to="/assistant/students" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.students') }}</router-link>
            <h1 class="mt-2 text-2xl font-bold text-ink-900">{{ $t('courses.enrollStudent') }}</h1>
            <p class="text-ink-500">{{ $t('courses.manageEnrollments') }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard :title="$t('courses.enrollmentCard')">
                <div class="space-y-4">
                    <AppSelect v-model="selectedCourse" :label="$t('common.course')" :options="courses" id="enroll-course" :placeholder="$t('common.selectCourse')" :error="errors.course_id" />
                    <AppSelect v-model="selectedStudent" :label="$t('common.student')" :options="students.map((s) => ({ value: s.id, label: s.name }))" id="enroll-student" :placeholder="$t('common.selectStudent')" :error="errors.student_id" />
                    <p v-if="!students.length" class="text-sm text-amber-700">{{ $t('students.noActiveStudents') }} <router-link to="/assistant/students/new" class="text-terracotta-600 hover:underline">{{ $t('students.createFirst') }}</router-link></p>
                </div>
            </AppCard>
            <div class="flex justify-end gap-2">
                <AppButton type="button" variant="outline" @click="router.push('/assistant/students')">{{ $t('common.cancel') }}</AppButton>
                <AppButton type="submit" :loading="enrolling" :disabled="!selectedCourse || !selectedStudent">{{ $t('students.enroll') }}</AppButton>
            </div>
        </form>
    </div>
</template>
