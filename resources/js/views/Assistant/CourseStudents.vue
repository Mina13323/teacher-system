<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { teacher, publicCatalog, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const courseId = route.params.id;
const loading = ref(true);
const error = ref('');
const course = ref(null);
const enrollments = ref([]);
const meta = ref(null);
const students = ref([]);
const selectedStudent = ref('');
const enrolling = ref(false);
const enrollError = ref({});
const removeTarget = ref(null);
const removeBusy = ref(false);

async function loadEnrollments() {
    const res = toList(await teacher.courseStudents(courseId, { per_page: 25 }));
    enrollments.value = res.items;
    meta.value = res.meta;
}

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const [c, s] = await Promise.all([
            publicCatalog.course(courseId),
            teacher.students({ per_page: 100 }),
        ]);
        course.value = c;
        students.value = toList(s).items.filter((x) => x.is_active);
        await loadEnrollments();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function enroll() {
    if (!selectedStudent.value) return;
    enrolling.value = true;
    enrollError.value = {};
    try {
        await teacher.enrollStudent(courseId, selectedStudent.value);
        toast.success('Student enrolled.');
        selectedStudent.value = '';
        await loadEnrollments();
    } catch (e) {
        enrollError.value = fieldErrors(e);
        toast.error(e.isValidation ? 'Please fix the highlighted fields.' : e.message);
    } finally {
        enrolling.value = false;
    }
}

async function remove() {
    removeBusy.value = true;
    try {
        await teacher.unenrollStudent(courseId, removeTarget.value.student_id);
        toast.success('Student unenrolled.');
        removeTarget.value = null;
        await loadEnrollments();
    } catch (e) {
        toast.error(e.message);
    } finally {
        removeBusy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <router-link to="/assistant/students" class="text-sm font-medium text-terracotta-600 hover:underline">← Back to students</router-link>
            <h1 class="mt-2 text-2xl font-bold text-ink-900">{{ course?.title || `Course #${courseId}` }}</h1>
            <p class="text-ink-500">Manage student enrollments for this course.</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <AppCard title="Enroll a student">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1"><AppSelect v-model="selectedStudent" label="Student" :options="students.map((s) => ({ value: s.id, label: s.name }))" id="enroll-student" placeholder="Select a student" :error="enrollError.student_id" /></div>
                    <AppButton :loading="enrolling" :disabled="!selectedStudent" @click="enroll">Enroll</AppButton>
                </div>
            </AppCard>

            <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                <div class="px-5 py-4"><h2 class="font-semibold text-ink-900">Enrolled students</h2></div>
                <EmptyState v-if="!enrollments.length" icon="users" title="No enrollments yet" message="Enroll students to see them here." />
                <div v-else class="divide-y divide-ink-100">
                    <div v-for="e in enrollments" :key="e.id" class="flex items-center gap-3 px-5 py-3.5">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (e.student?.name || 'U').slice(0, 1) }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink-800">{{ e.student?.name }}</p>
                            <p class="truncate text-xs text-ink-400">{{ e.student?.email }}</p>
                        </div>
                        <AppBadge :tone="e.status === 'active' ? 'success' : 'neutral'">{{ e.status }}</AppBadge>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="removeTarget = e">Remove</button>
                    </div>
                </div>
            </div>
        </template>

        <ConfirmDialog :open="Boolean(removeTarget)" title="Unenroll student?" :message="`Remove ${removeTarget?.student?.name} from this course?`" confirm-text="Unenroll" :loading="removeBusy" @close="removeTarget = null" @confirm="remove" />
    </div>
</template>
