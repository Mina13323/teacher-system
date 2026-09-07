<script setup>
import { ref, onMounted, computed } from 'vue';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import StatCard from '@/components/ui/StatCard.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSelect from '@/components/ui/AppSelect.vue';

const toast = useToast();
const overview = ref(null);
const loading = ref(true);
const error = ref('');

const courses = ref([]);
const selectedCourse = ref('');
const courseStats = ref(null);
const caLoading = ref(false);

async function loadOverview() {
    loading.value = true;
    error.value = '';
    try {
        overview.value = await teacher.analyticsOverview();
        const res = toList(await teacher.courses({ per_page: 100 }));
        courses.value = res.items.map((c) => ({ value: c.id, label: c.title }));
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function loadCourse() {
    if (!selectedCourse.value) return;
    caLoading.value = true;
    try {
        courseStats.value = await teacher.courseAnalytics(selectedCourse.value);
    } catch (e) {
        toast.error(e.message);
    } finally {
        caLoading.value = false;
    }
}

const weakAreas = computed(() => courseStats.value?.weak_areas || []);
const topPerformers = computed(() => courseStats.value?.top_performers || []);

onMounted(loadOverview);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-ink-900">Analytics</h1>
            <p class="text-sm text-ink-500">Insights into your students' learning and performance.</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Courses" :value="overview.courses_count" icon="book" tone="terracotta" :hint="`${overview.published_courses_count} published`" />
                <StatCard label="Students" :value="overview.students_count" icon="users" tone="emerald" />
                <StatCard label="Enrollments" :value="overview.enrollments_count" icon="layers" tone="sky" />
                <StatCard label="Attempts" :value="overview.attempts_count" icon="clipboard" tone="amber" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard label="Average score" :value="overview.average_score ?? '—'" icon="chart" tone="ink" />
                <StatCard label="Pass rate" :value="overview.pass_rate !== null ? overview.pass_rate + '%' : '—'" icon="check" tone="emerald" />
                <StatCard label="Flagged integrity" :value="overview.flagged_integrity_count" icon="shield" tone="danger" />
            </div>

            <AppCard title="Course analytics">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <AppSelect v-model="selectedCourse" label="Course" :options="courses" id="analytics-course" placeholder="Select a course" class="flex-1" />
                    <AppButton :loading="caLoading" :disabled="!selectedCourse" @click="loadCourse">View</AppButton>
                </div>

                <div v-if="courseStats" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Students" :value="courseStats.students_count" icon="users" tone="terracotta" />
                    <StatCard label="Avg completion" :value="courseStats.average_lesson_completion + '%'" icon="layers" tone="sky" />
                    <StatCard label="Attempts" :value="courseStats.attempts_count" icon="clipboard" tone="amber" />
                    <StatCard label="Avg score" :value="courseStats.average_score ?? '—'" icon="chart" tone="ink" />
                </div>

                <div v-if="courseStats" class="mt-6 grid gap-6 lg:grid-cols-2">
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-ink-700">Weak areas</h3>
                        <p v-if="!weakAreas.length" class="text-sm text-ink-400">No data yet.</p>
                        <div v-else class="space-y-2">
                            <div v-for="w in weakAreas" :key="w.exam_id" class="flex items-center justify-between rounded-lg bg-ink-50 px-4 py-2.5 text-sm">
                                <span class="text-ink-800">{{ w.title }}</span>
                                <span class="font-semibold text-terracotta-600">{{ w.average ?? '—' }}%</span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-ink-700">Top performers</h3>
                        <p v-if="!topPerformers.length" class="text-sm text-ink-400">No data yet.</p>
                        <div v-else class="space-y-2">
                            <router-link v-for="t in topPerformers" :key="t.student_id" :to="`/teacher/analytics/students/${t.student_id}`" class="flex items-center justify-between rounded-lg bg-emerald-50 px-4 py-2.5 text-sm hover:bg-emerald-100">
                                <span class="font-medium text-emerald-800">{{ t.display_name }}</span>
                                <span class="font-semibold text-emerald-600">{{ t.average }}%</span>
                            </router-link>
                        </div>
                    </div>
                </div>
            </AppCard>
        </template>
    </div>
</template>
