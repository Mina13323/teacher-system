<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { useToast } from '@/composables/toast';
import { publicCatalog, student } from '@/api';
import { useAuthStore } from '@/stores/auth';
import Icon from '@/components/ui/Icon.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const auth = useAuthStore();
const toast = useToast();
const enrolling = ref(false);
const { loading, error, data, run } = useAsync(() => publicCatalog.course(route.params.id));
const isEnrolled = computed(() => data.value?.is_enrolled === true);
const hasEnrollmentRecord = computed(() => Boolean(data.value?.enrollment_status));
const hasActiveAccess = computed(() => auth.accessStatus === 'active' && !auth.isSuspended);
const hasCourseCapability = computed(() => auth.canAccessLessons || auth.canTakeExams);
const canSelfEnroll = computed(() => auth.isStudent
    && hasCourseCapability.value
    && hasActiveAccess.value
    && !hasEnrollmentRecord.value);
const portalHome = computed(() => auth.isStudent ? '/student' : auth.isAssistant ? '/assistant' : auth.isAdmin ? '/admin' : '/teacher');

async function enroll() {
    if (!data.value || !canSelfEnroll.value || enrolling.value) return;

    enrolling.value = true;
    try {
        await student.enroll(data.value.id);
        toast.success(t('catalog.enrolledToast'));
        await run();
        router.push(auth.canAccessLessons ? `/student/courses/${data.value.id}` : '/student/exams');
    } catch (e) {
        toast.error(e.message || t('catalog.enrollError'));
    } finally {
        enrolling.value = false;
    }
}

function openEnrolledCourse() {
    if (!data.value || !hasActiveAccess.value || !hasCourseCapability.value) return;
    router.push(auth.canAccessLessons ? `/student/courses/${data.value.id}` : '/student/exams');
}

function lessonAccessMessage() {
    if (!hasActiveAccess.value) return t('catalog.activeAccessRequired');
    if (isEnrolled.value) return t('catalog.lessonAccessRestricted');
    if (hasEnrollmentRecord.value) return t('catalog.enrollmentNotActive');
    if (!auth.canAccessLessons) return t('catalog.lessonAccessRestricted');
    return t('catalog.enrollToStudy');
}

onMounted(() => run());

// `units` (+ each unit's `lessons` + each lesson's `videos`) are nested resource
// collections (`{ data: [...] }`), so normalise to plain arrays.
const units = computed(() => {
    const us = data.value?.units;
    if (!us) return [];
    const arr = Array.isArray(us) ? us : (us.data || []);
    return arr.map((u) => {
        const lessonsRaw = Array.isArray(u.lessons) ? u.lessons : (u.lessons?.data || []);
        return {
            ...u,
            lessons: lessonsRaw.map((l) => ({
                ...l,
                videos: Array.isArray(l.videos) ? l.videos : (l.videos?.data || []),
            })),
        };
    });
});
</script>

<template>
    <div class="min-h-screen bg-parchment-50">
        <header class="sticky top-0 z-20 border-b border-ink-100 bg-parchment-50/95 pt-safe backdrop-blur">
            <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-3 sm:px-4">
                <div class="flex items-center gap-2 min-w-0">
                    <Icon name="compass" :size="22" class="text-terracotta-600 shrink-0" />
                    <span class="truncate font-display text-base sm:text-lg font-semibold text-ink-900">{{ $t('app.brand') }}</span>
                </div>
                <nav class="flex items-center gap-2 sm:gap-3 shrink-0">
                    <LanguageSwitcher class="scale-90 sm:scale-100" />
                    <router-link to="/courses" class="text-xs sm:text-sm font-medium text-ink-600 hover:text-ink-900">{{ $t('nav.courses') }}</router-link>
                    <router-link v-if="!auth.isAuthenticated" to="/login"><AppButton size="sm">{{ $t('auth.signIn') }}</AppButton></router-link>
                    <router-link v-else :to="portalHome"><AppButton size="sm" variant="outline">{{ $t('catalog.portal') }}</AppButton></router-link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-10">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>
            <template v-else>
                <router-link to="/courses" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('catalog.allCourses') }}</router-link>
                <div class="mt-4 rounded-xl border border-ink-100 bg-white p-6 shadow-sm">
                    <span class="inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">{{ $t(`status.${data.status}`, data.status) }}</span>
                    <h1 class="mt-3 font-display text-3xl font-bold text-ink-900" dir="auto">{{ data.title }}</h1>
                    <p class="mt-2 max-w-2xl text-ink-600" dir="auto">{{ data.description }}</p>
                    <div class="mt-4 flex items-center gap-3 text-sm text-ink-400">
                        <span>{{ $t('courses.unitsCount', { n: data.units_count || 0 }) }}</span>
                        <span class="h-1 w-1 rounded-full bg-ink-300" />
                        <span>{{ $t('courses.lessonsCount', { n: data.lessons_count || 0 }) }}</span>
                    </div>
                    <div v-if="auth.isStudent" class="mt-5 flex flex-wrap items-center gap-3">
                        <AppButton v-if="isEnrolled" variant="outline" :disabled="!hasActiveAccess || !hasCourseCapability" @click="openEnrolledCourse">
                            {{ auth.canAccessLessons ? $t('catalog.goToCourse') : $t('catalog.goToExams') }}
                        </AppButton>
                        <AppButton v-else :loading="enrolling" :disabled="!canSelfEnroll" @click="enroll">{{ $t('catalog.enrollNow') }}</AppButton>
                        <p v-if="isEnrolled && !hasActiveAccess" class="text-sm text-amber-700">{{ $t('catalog.activeAccessRequired') }}</p>
                        <p v-else-if="isEnrolled && !hasCourseCapability" class="text-sm text-amber-700">{{ $t('catalog.courseCapabilityRequired') }}</p>
                        <p v-else-if="!isEnrolled && !canSelfEnroll" class="text-sm text-amber-700">
                            {{ hasEnrollmentRecord ? $t('catalog.enrollmentNotActive') : $t('catalog.enrollmentUnavailable') }}
                        </p>
                    </div>
                </div>

                <div class="mt-8 space-y-6">
                    <div v-for="unit in units" :key="unit.id" class="rounded-xl border border-ink-100 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-ink-900" dir="auto">{{ unit.title }}</h2>
                        <p v-if="unit.description" class="mt-1 text-sm text-ink-500" dir="auto">{{ unit.description }}</p>
                        <div class="mt-4 space-y-2">
                            <div v-for="lesson in unit.lessons" :key="lesson.id" class="flex items-start gap-3 rounded-lg bg-parchment-50 px-4 py-3">
                                <Icon name="play" :size="18" class="mt-0.5 text-terracotta-500" />
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-ink-800" dir="auto">{{ lesson.title }}</p>
                                    <p v-if="lesson.videos?.length" class="text-xs text-ink-400">{{ $t('courses.videosCount', { n: lesson.videos.length }) }}</p>
                                </div>
                                <router-link v-if="auth.isStudent && isEnrolled && auth.canAccessLessons && hasActiveAccess" :to="`/student/lessons/${lesson.id}`" class="text-xs font-medium text-terracotta-600 hover:underline">{{ $t('catalog.openLesson') }}</router-link>
                                <span v-else-if="auth.isStudent" class="text-xs text-ink-400">{{ lessonAccessMessage() }}</span>
                                <span v-else class="text-xs text-ink-400">{{ $t('catalog.signInToStudy') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </main>
    </div>
</template>
