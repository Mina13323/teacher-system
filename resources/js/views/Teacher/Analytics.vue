<script setup>
import { ref, onMounted, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import StatCard from '@/components/ui/StatCard.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppButton from '@/components/ui/AppButton.vue';

const { t } = useI18n();
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
            <h1 class="text-2xl font-bold text-ink-900">{{ $t('analytics.title') }}</h1>
            <p class="text-sm text-ink-500">{{ $t('analytics.subtitle') }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard :label="$t('analytics.courses')" :value="overview.courses_count" icon="book" tone="terracotta" :hint="$t('analytics.publishedHint', { n: overview.published_courses_count })" />
                <StatCard :label="$t('analytics.students')" :value="overview.students_count" icon="users" tone="emerald" />
                <StatCard :label="$t('analytics.enrollments')" :value="overview.enrollments_count" icon="layers" tone="sky" />
                <StatCard :label="$t('analytics.attempts')" :value="overview.attempts_count" icon="clipboard" tone="amber" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard :label="$t('analytics.averageScore')" :value="overview.average_score ?? '—'" icon="chart" tone="ink" />
                <StatCard :label="$t('dashboard.statPassRate')" :value="overview.pass_rate !== null ? overview.pass_rate + '%' : '—'" icon="check" tone="emerald" />
                <StatCard :label="$t('analytics.flaggedIntegrity')" :value="overview.flagged_integrity_count" icon="shield" tone="danger" />
            </div>

            <AppCard :title="$t('analytics.courseAnalytics')">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <AppSelect v-model="selectedCourse" :label="$t('common.course')" :options="courses" id="analytics-course" :placeholder="$t('common.selectCourse')" class="flex-1" />
                    <AppButton :loading="caLoading" :disabled="!selectedCourse" @click="loadCourse">{{ $t('analytics.view') }}</AppButton>
                </div>

                <div v-if="courseStats" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard :label="$t('analytics.students')" :value="courseStats.students_count" icon="users" tone="terracotta" />
                    <StatCard :label="$t('analytics.avgCompletion')" :value="courseStats.average_lesson_completion + '%'" icon="layers" tone="sky" />
                    <StatCard :label="$t('analytics.attempts')" :value="courseStats.attempts_count" icon="clipboard" tone="amber" />
                    <StatCard :label="$t('analytics.avgScore')" :value="courseStats.average_score ?? '—'" icon="chart" tone="ink" />
                </div>

                <div v-if="courseStats" class="mt-6 grid gap-6 lg:grid-cols-2">
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-ink-700">{{ $t('analytics.weakAreas') }}</h3>
                        <p v-if="!weakAreas.length" class="text-sm text-ink-400">{{ $t('common.noData') }}</p>
                        <div v-else class="space-y-2">
                            <div v-for="w in weakAreas" :key="w.exam_id" class="flex items-center justify-between rounded-lg bg-ink-50 px-4 py-2.5 text-sm">
                                <span class="text-ink-800" dir="auto">{{ w.title }}</span>
                                <span class="font-semibold text-terracotta-600">{{ w.average ?? '—' }}%</span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-ink-700">{{ $t('analytics.topPerformers') }}</h3>
                        <p v-if="!topPerformers.length" class="text-sm text-ink-400">{{ $t('common.noData') }}</p>
                        <div v-else class="space-y-2">
                            <router-link v-for="p in topPerformers" :key="p.student_id" :to="`/teacher/analytics/students/${p.student_id}`" class="flex items-center justify-between rounded-lg bg-emerald-50 px-4 py-2.5 text-sm hover:bg-emerald-100">
                                <span class="font-medium text-emerald-800" dir="auto">{{ p.display_name }}</span>
                                <span class="font-semibold text-emerald-600">{{ p.average }}%</span>
                            </router-link>
                        </div>
                    </div>
                </div>
            </AppCard>
        </template>
    </div>
</template>
