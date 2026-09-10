<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import Icon from '@/components/ui/Icon.vue';

const { t } = useI18n();
const route = useRoute();
const toast = useToast();
const id = route.params.id;

const student = ref(null);
const loading = ref(true);
const error = ref('');

const courses = ref([]);
const selectedCourse = ref('');
const enrolling = ref(false);

async function loadCourses() {
    try {
        const res = toList(await teacher.courses({ per_page: 100 }));
        courses.value = res.items.map((c) => ({ value: c.id, label: c.title }));
    } catch {
        courses.value = [];
    }
}

async function enroll() {
    if (!selectedCourse.value) return;
    enrolling.value = true;
    try {
        await teacher.enrollStudent(selectedCourse.value, id);
        toast.success(t('students.enrolled'));
        selectedCourse.value = '';
    } catch (e) {
        toast.error(e.message);
    } finally {
        enrolling.value = false;
    }
}

onMounted(async () => {
    try {
        student.value = await teacher.student(id);
        await loadCourses();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link to="/teacher/students" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.students') }}</router-link>
            <h1 class="mt-2 text-2xl font-bold text-ink-900" dir="auto">{{ student?.name || $t('common.student') }}</h1>
            <p class="text-ink-500">{{ student?.email }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-3">
                <AppCard>
                    <div class="flex items-center gap-3">
                        <Icon name="user" :size="20" class="text-terracotta-500" />
                        <p class="font-medium text-ink-800" dir="auto">{{ student.name }}</p>
                    </div>
                    <AppBadge :tone="student.is_active ? 'success' : 'neutral'" class="mt-3">{{ student.is_active ? $t('status.active') : $t('status.inactive') }}</AppBadge>
                </AppCard>
                <AppCard>
                    <p class="text-sm text-ink-500">{{ $t('students.profileLabel') }}</p>
                    <AppBadge :tone="student.profile_completed ? 'primary' : 'warning'" class="mt-2">{{ student.profile_completed ? $t('status.complete') : $t('status.incomplete') }}</AppBadge>
                </AppCard>
                <AppCard>
                    <p class="text-sm text-ink-500">{{ $t('students.joined') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink-800">{{ student.created_at ? new Date(student.created_at).toLocaleDateString() : '—' }}</p>
                </AppCard>
            </div>

            <AppCard :title="$t('students.enrollIntoCourse')">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <AppSelect v-model="selectedCourse" :label="$t('common.course')" :options="courses" id="enroll-course" :placeholder="$t('common.selectCourse')" class="flex-1" />
                    <AppButton :loading="enrolling" :disabled="!selectedCourse" @click="enroll">{{ $t('students.enroll') }}</AppButton>
                </div>
                <p v-if="!courses.length" class="mt-3 text-sm text-ink-400">{{ $t('students.noCoursesToEnroll') }}</p>
            </AppCard>

            <div class="flex gap-3">
                <router-link :to="`/teacher/students/${student.id}/edit`"><AppButton variant="outline">{{ $t('common.edit') }}</AppButton></router-link>
                <router-link :to="`/teacher/analytics/students/${student.id}`"><AppButton variant="secondary">{{ $t('students.viewAnalytics') }}</AppButton></router-link>
            </div>
        </template>
    </div>
</template>
