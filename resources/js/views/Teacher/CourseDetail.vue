<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppModal from '@/components/ui/AppModal.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Icon from '@/components/ui/Icon.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();
const courseId = route.params.id;

const tab = ref('content');
const { loading, error, data, run } = useAsync(async () => {
    const c = await teacher.course(courseId);
    await loadExams();
    await loadStudents();
    return c;
});

const exams = ref([]);
const examsMeta = ref(null);
const students = ref([]);
const studentsMeta = ref(null);

async function loadExams(p = 1) {
    const res = toList(await teacher.exams(courseId, { per_page: 15, page: p }));
    exams.value = res.items;
    examsMeta.value = res.meta;
}
async function loadStudents(p = 1) {
    const res = toList(await teacher.courseStudents(courseId, { per_page: 15, page: p }));
    students.value = res.items;
    studentsMeta.value = res.meta;
}

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

const tabs = computed(() => [
    { key: 'content', label: t('courses.contentTab') },
    { key: 'exams', label: t('courses.examsTab') },
    { key: 'students', label: t('courses.studentsTab') },
]);

async function refresh() {
    await run();
    loadExams(1);
    loadStudents(1);
}

// ---- Unit modal ----
const unitModal = ref(false);
const unitForm = reactive({ id: null, title: '', description: '' });
const unitErrors = ref({});
const unitBusy = ref(false);
function openUnit(unit = null) {
    unitForm.id = unit?.id || null;
    unitForm.title = unit?.title || '';
    unitForm.description = unit?.description || '';
    unitErrors.value = {};
    unitModal.value = true;
}
async function saveUnit() {
    unitBusy.value = true;
    unitErrors.value = {};
    try {
        if (unitForm.id) await teacher.updateUnit(unitForm.id, { title: unitForm.title, description: unitForm.description || null });
        else await teacher.createUnit(courseId, { title: unitForm.title, description: unitForm.description || null });
        toast.success(unitForm.id ? t('courses.unitUpdated') : t('courses.unitCreated'));
        unitModal.value = false;
        refresh();
    } catch (e) {
        unitErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        unitBusy.value = false;
    }
}

// ---- Lesson modal ----
const lessonModal = ref(false);
const lessonForm = reactive({ id: null, unitId: null, title: '', description: '', content: '', is_published: true });
const lessonErrors = ref({});
const lessonBusy = ref(false);
function openLesson(unit, lesson = null) {
    lessonForm.id = lesson?.id || null;
    lessonForm.unitId = unit.id;
    lessonForm.title = lesson?.title || '';
    lessonForm.description = lesson?.description || '';
    lessonForm.content = lesson?.content || '';
    lessonForm.is_published = lesson ? Boolean(lesson.is_published) : true;
    lessonErrors.value = {};
    lessonModal.value = true;
}
async function saveLesson() {
    lessonBusy.value = true;
    lessonErrors.value = {};
    const payload = { title: lessonForm.title, description: lessonForm.description || null, content: lessonForm.content || null, is_published: lessonForm.is_published };
    try {
        if (lessonForm.id) await teacher.updateLesson(lessonForm.id, payload);
        else await teacher.createLesson(lessonForm.unitId, payload);
        toast.success(lessonForm.id ? t('courses.lessonUpdated') : t('courses.lessonCreated'));
        lessonModal.value = false;
        refresh();
    } catch (e) {
        lessonErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        lessonBusy.value = false;
    }
}

// ---- Video modal ----
const videoModal = ref(false);
const videoForm = reactive({ id: null, lessonId: null, title: '', provider: 'youtube', provider_video_id: '', storage_path: '', duration: 0, is_published: true });
const videoErrors = ref({});
const videoBusy = ref(false);
const providerOptions = computed(() => [
    { value: 'youtube', label: t('courses.youtube') },
    { value: 'storage', label: t('courses.storage') },
]);
function openVideo(lesson, video = null) {
    videoForm.id = video?.id || null;
    videoForm.lessonId = lesson.id;
    videoForm.title = video?.title || '';
    videoForm.provider = video?.provider || 'youtube';
    videoForm.provider_video_id = video?.provider_video_id || '';
    videoForm.storage_path = video?.storage_path || '';
    videoForm.duration = video?.duration || 0;
    videoForm.is_published = video ? Boolean(video.is_published) : true;
    videoErrors.value = {};
    videoModal.value = true;
}
async function saveVideo() {
    videoBusy.value = true;
    videoErrors.value = {};
    const payload = { title: videoForm.title, provider: videoForm.provider, duration: videoForm.duration || 0, is_published: videoForm.is_published };
    if (videoForm.provider === 'youtube') payload.provider_video_id = videoForm.provider_video_id;
    else payload.storage_path = videoForm.storage_path;
    try {
        if (videoForm.id) await teacher.updateVideo(videoForm.id, payload);
        else await teacher.createVideo(videoForm.lessonId, payload);
        toast.success(videoForm.id ? t('courses.videoUpdated') : t('courses.videoCreated'));
        videoModal.value = false;
        refresh();
    } catch (e) {
        videoErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        videoBusy.value = false;
    }
}

// ---- Delete / publish ----
const confirmTarget = ref(null);
const confirmKind = ref('');
const confirmBusy = ref(false);
function askDelete(target, kind) { confirmTarget.value = target; confirmKind.value = kind; }
const confirmTitle = computed(() => confirmKind.value === 'enrollment' ? t('courses.unenrollTitle') : t('common.confirmDelete'));
const confirmMessage = computed(() => confirmKind.value === 'enrollment'
    ? t('courses.removeStudentMessage')
    : t('courses.deleteKindMessage', { kind: t(`courses.${confirmKind.value}`, confirmKind.value) }));
async function runDelete() {
    confirmBusy.value = true;
    try {
        if (confirmKind.value === 'unit') await teacher.deleteUnit(confirmTarget.value.id);
        if (confirmKind.value === 'lesson') await teacher.deleteLesson(confirmTarget.value.id);
        if (confirmKind.value === 'video') await teacher.deleteVideo(confirmTarget.value.id);
        if (confirmKind.value === 'enrollment') await teacher.unenrollStudent(courseId, confirmTarget.value.student_id);
        toast.success(confirmKind.value === 'enrollment' ? t('students.unenrolled') : t('common.deleted'));
        confirmTarget.value = null;
        refresh();
    } catch (e) {
        toast.error(e.message);
    } finally {
        confirmBusy.value = false;
    }
}
async function toggleLesson(lesson) {
    await teacher[lesson.is_published ? 'unpublishLesson' : 'publishLesson'](lesson.id);
    lesson.is_published = !lesson.is_published;
    toast.success(lesson.is_published ? t('courses.lessonPublished') : t('courses.lessonUnpublished'));
}
async function toggleVideo(video) {
    await teacher[video.is_published ? 'unpublishVideo' : 'publishVideo'](video.id);
    video.is_published = !video.is_published;
    toast.success(video.is_published ? t('courses.videoPublished') : t('courses.videoUnpublished'));
}

// ---- Reorder ----
async function moveUnit(unit, dir) {
    const list = units.value;
    const i = list.findIndex((u) => u.id === unit.id);
    const j = i + dir;
    if (j < 0 || j >= list.length) return;
    const ordered = list.map((u) => u.id);
    [ordered[i], ordered[j]] = [ordered[j], ordered[i]];
    try { await teacher.reorderUnits(courseId, ordered); toast.success(t('courses.unitsReordered')); }
    catch (e) { toast.error(e.message); }
    finally { refresh(); }
}
async function moveLesson(unit, lesson, dir) {
    const lessons = unit.lessons;
    const i = lessons.findIndex((l) => l.id === lesson.id);
    const j = i + dir;
    if (j < 0 || j >= lessons.length) return;
    const ordered = lessons.map((l) => l.id);
    [ordered[i], ordered[j]] = [ordered[j], ordered[i]];
    try { await teacher.reorderLessons(unit.id, ordered); toast.success(t('courses.lessonsReordered')); }
    catch (e) { toast.error(e.message); }
    finally { refresh(); }
}

// ---- Exam create ----
const examModal = ref(false);
const examForm = reactive({ title: '', description: '', duration_minutes: 30, pass_percentage: 50, max_attempts: 1, shuffle_questions: false, shuffle_options: false, show_result_immediately: true });
const examErrors = ref({});
const examBusy = ref(false);
async function saveExam() {
    examBusy.value = true;
    examErrors.value = {};
    try {
        await teacher.createExam(courseId, examForm);
        toast.success(t('courses.examCreated'));
        examModal.value = false;
        loadExams(1);
    } catch (e) {
        examErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        examBusy.value = false;
    }
}

// ---- Enroll student ----
const enrollModal = ref(false);
const allStudents = ref([]);
const selectedStudent = ref('');
const enrollBusy = ref(false);
const enrollError = ref({});
async function openEnroll() {
    try {
        const res = toList(await teacher.students({ per_page: 100 }));
        allStudents.value = res.items.map((s) => ({ value: s.id, label: s.name }));
    } catch (e) {
        allStudents.value = [];
        toast.error(e.message);
    }
    selectedStudent.value = '';
    enrollError.value = {};
    enrollModal.value = true;
}
async function doEnroll() {
    if (!selectedStudent.value) return;
    enrollBusy.value = true;
    enrollError.value = {};
    try {
        await teacher.enrollStudent(courseId, selectedStudent.value);
        toast.success(t('students.enrolled'));
        enrollModal.value = false;
        loadStudents(1);
    } catch (e) {
        enrollError.value = fieldErrors(e);
        toast.error(e.isValidation ? t('common.fixFields') : e.message);
    } finally {
        enrollBusy.value = false;
    }
}

onMounted(async () => { await run(); });
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link to="/teacher/courses" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.courses') }}</router-link>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900" dir="auto">{{ data?.title || $t('common.course') }}</h1>
                <AppBadge :tone="data?.status === 'published' ? 'success' : 'neutral'">{{ data?.status ? $t(`status.${data.status}`, data.status) : '' }}</AppBadge>
                <router-link :to="`/teacher/courses/${courseId}/edit`"><AppButton variant="outline" size="sm">{{ $t('common.edit') }}</AppButton></router-link>
            </div>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <Tabs :tabs="tabs" v-model="tab" />

            <div v-if="tab === 'content'" class="space-y-5">
                <div class="flex justify-end"><AppButton variant="outline" @click="openUnit()">{{ $t('courses.addUnitLabel') }}</AppButton></div>
                <EmptyState v-if="!units.length" icon="layers" :title="$t('courses.noUnitsTitle')" :message="$t('courses.buildUnits')">
                    <AppButton @click="openUnit()">{{ $t('courses.addUnitLabel') }}</AppButton>
                </EmptyState>
                <div v-else class="space-y-5">
                    <div v-for="unit in units" :key="unit.id" class="rounded-xl border border-ink-100 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <h3 class="flex items-center gap-2 text-lg font-semibold text-ink-900">
                                    <span dir="auto">{{ unit.title }}</span> <span class="text-xs font-normal text-ink-400">{{ $t('common.unitN', { n: unit.position }) }}</span>
                                </h3>
                                <p v-if="unit.description" class="mt-1 text-sm text-ink-500" dir="auto">{{ unit.description }}</p>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button class="rounded p-1.5 text-ink-400 hover:bg-ink-100 disabled:opacity-40" :disabled="unit.position <= 1" :aria-label="$t('common.previous')" @click="moveUnit(unit, -1)">↑</button>
                                <button class="rounded p-1.5 text-ink-400 hover:bg-ink-100 disabled:opacity-40" :disabled="unit.position >= units.length" :aria-label="$t('common.next')" @click="moveUnit(unit, 1)">↓</button>
                                <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100" @click="openUnit(unit)">{{ $t('common.edit') }}</button>
                                <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="askDelete(unit, 'unit')">{{ $t('common.delete') }}</button>
                            </div>
                        </div>

                        <div class="mt-4 space-y-3">
                            <div v-for="lesson in unit.lessons" :key="lesson.id" class="rounded-lg border border-ink-100 bg-parchment-50/50 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-ink-800" dir="auto">{{ lesson.title }}</p>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-ink-400">
                                            <AppBadge :tone="lesson.is_published ? 'success' : 'neutral'">{{ lesson.is_published ? $t('status.published') : $t('status.draft') }}</AppBadge>
                                            <button class="rounded px-2 py-1 font-medium text-ink-500 hover:bg-ink-100" @click="openLesson(unit, lesson)">{{ $t('common.edit') }}</button>
                                            <button class="rounded px-2 py-1 font-medium text-amber-600" @click="toggleLesson(lesson)">{{ lesson.is_published ? $t('courses.unpublish') : $t('courses.publish') }}</button>
                                            <button class="rounded px-2 py-1 font-medium text-rose-600" @click="askDelete(lesson, 'lesson')">{{ $t('common.delete') }}</button>
                                            <button class="rounded px-2 py-1 font-medium text-ink-400 disabled:opacity-40" :disabled="unit.lessons.findIndex((l) => l.id === lesson.id) === 0" @click="moveLesson(unit, lesson, -1)">↑</button>
                                            <button class="rounded px-2 py-1 font-medium text-ink-400 disabled:opacity-40" :disabled="unit.lessons.findIndex((l) => l.id === lesson.id) === unit.lessons.length - 1" @click="moveLesson(unit, lesson, 1)">↓</button>
                                        </div>
                                    </div>
                                    <AppButton variant="outline" size="sm" @click="openVideo(lesson)">{{ $t('courses.addVideoLabel') }}</AppButton>
                                </div>
                                <div v-if="lesson.videos?.length" class="mt-3 space-y-2">
                                    <div v-for="video in lesson.videos" :key="video.id" class="flex items-center gap-3 rounded-lg bg-white px-3 py-2 shadow-sm">
                                        <Icon name="play" :size="16" class="text-terracotta-500" />
                                        <span class="min-w-0 flex-1 truncate text-sm text-ink-800" dir="auto">{{ video.title }}</span>
                                        <span class="text-xs text-ink-400">{{ video.provider }}</span>
                                        <AppBadge :tone="video.is_published ? 'success' : 'neutral'">{{ video.is_published ? $t('common.live') : $t('status.draft') }}</AppBadge>
                                        <button class="rounded px-2 py-1 text-xs font-medium text-ink-500 hover:bg-ink-100" @click="openVideo(lesson, video)">{{ $t('common.edit') }}</button>
                                        <button class="rounded px-2 py-1 text-xs font-medium text-amber-600" @click="toggleVideo(video)">{{ video.is_published ? $t('courses.unpub') : $t('courses.publish') }}</button>
                                        <button class="rounded px-2 py-1 text-xs font-medium text-rose-600" @click="askDelete(video, 'video')">{{ $t('common.delete') }}</button>
                                    </div>
                                </div>
                            </div>
                            <AppButton variant="outline" size="sm" @click="openLesson(unit)">{{ $t('courses.addLessonLabel') }}</AppButton>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="tab === 'exams'" class="space-y-4">
                <div class="flex justify-end"><AppButton @click="examModal = true">{{ $t('courses.createExam') }}</AppButton></div>
                <EmptyState v-if="!exams.length" icon="clipboard" :title="$t('courses.noExamsTitle')" :message="$t('courses.noExamsMessage')">
                    <AppButton @click="examModal = true">{{ $t('courses.createExam') }}</AppButton>
                </EmptyState>
                <div v-else class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                    <div class="divide-y divide-ink-100">
                        <div v-for="e in exams" :key="e.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <Icon name="clipboard" :size="20" class="text-terracotta-500" />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800" dir="auto">{{ e.title }}</p>
                                <p class="text-xs text-ink-400">{{ $t('courses.examSummary', { questions: e.questions_count, attempts: e.attempts_count }) }}</p>
                            </div>
                            <AppBadge :tone="e.status === 'published' ? 'success' : 'neutral'">{{ $t(`status.${e.status}`, e.status) }}</AppBadge>
                            <router-link :to="`/teacher/exams/${e.id}`"><AppButton variant="outline" size="sm">{{ $t('common.manage') }}</AppButton></router-link>
                        </div>
                    </div>
                    <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="examsMeta" :meta="examsMeta" @change="loadExams" /></div>
                </div>
            </div>

            <div v-else class="space-y-4">
                <div class="flex justify-end"><AppButton @click="openEnroll">{{ $t('courses.enrollStudent') }}</AppButton></div>
                <EmptyState v-if="!students.length" icon="users" :title="$t('courses.noEnrollmentsTitle')" :message="$t('courses.noEnrollmentsMessage')">
                    <AppButton @click="openEnroll">{{ $t('courses.enrollStudent') }}</AppButton>
                </EmptyState>
                <div v-else class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                    <div class="divide-y divide-ink-100">
                        <div v-for="s in students" :key="s.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (s.student?.name || 'U').slice(0, 1) }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800" dir="auto">{{ s.student?.name }}</p>
                                <p class="text-xs text-ink-400">{{ s.student?.email }} · {{ $t('common.enrolledAt', { date: s.enrolled_at ? new Date(s.enrolled_at).toLocaleDateString() : '—' }) }}</p>
                            </div>
                            <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="askDelete(s, 'enrollment')">{{ $t('students.unenroll') }}</button>
                        </div>
                    </div>
                    <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="studentsMeta" :meta="studentsMeta" @change="loadStudents" /></div>
                </div>
            </div>
        </template>

        <!-- Unit modal -->
        <AppModal :open="unitModal" :title="unitForm.id ? $t('courses.editUnit') : $t('courses.addUnitLabel')" size="md" @close="unitModal = false">
            <form class="space-y-4" @submit.prevent="saveUnit">
                <AppInput v-model="unitForm.title" :label="$t('courses.unitTitle')" required id="unit-title" :error="unitErrors.title" />
                <AppTextarea v-model="unitForm.description" :label="$t('courses.description')" id="unit-desc" :error="unitErrors.description" :rows="2" />
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="unitModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="unitBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Lesson modal -->
        <AppModal :open="lessonModal" :title="lessonForm.id ? $t('courses.editLesson') : $t('courses.addLessonLabel')" size="lg" @close="lessonModal = false">
            <form class="space-y-4" @submit.prevent="saveLesson">
                <AppInput v-model="lessonForm.title" :label="$t('courses.lessonTitle')" required id="lesson-title" :error="lessonErrors.title" />
                <AppInput v-model="lessonForm.description" :label="$t('courses.summaryField')" id="lesson-desc" :error="lessonErrors.description" />
                <AppTextarea v-model="lessonForm.content" :label="$t('courses.content')" id="lesson-content" :error="lessonErrors.content" :rows="5" />
                <label class="flex items-center gap-2 text-sm text-ink-700"><input type="checkbox" v-model="lessonForm.is_published" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" /> {{ $t('status.published') }}</label>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="lessonModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="lessonBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Video modal -->
        <AppModal :open="videoModal" :title="videoForm.id ? $t('courses.editVideo') : $t('courses.addVideoLabel')" size="lg" @close="videoModal = false">
            <form class="space-y-4" @submit.prevent="saveVideo">
                <AppInput v-model="videoForm.title" :label="$t('courses.titleField')" required id="video-title" :error="videoErrors.title" />
                <AppSelect v-model="videoForm.provider" :label="$t('courses.provider')" :options="providerOptions" id="video-provider" :error="videoErrors.provider" />
                <template v-if="videoForm.provider === 'youtube'">
                    <AppInput v-model="videoForm.provider_video_id" :label="$t('courses.youtubeId')" id="video-vid" :error="videoErrors.provider_video_id" placeholder="dQw4w9WgXcQ" />
                </template>
                <template v-else>
                    <AppInput v-model="videoForm.storage_path" :label="$t('courses.storagePath')" id="video-storage" :error="videoErrors.storage_path" />
                </template>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="videoForm.duration" :label="$t('courses.durationSeconds')" type="number" id="video-duration" :error="videoErrors.duration" />
                    <label class="flex items-center gap-2 text-sm text-ink-700"><input type="checkbox" v-model="videoForm.is_published" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" /> {{ $t('status.published') }}</label>
                </div>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="videoModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="videoBusy">{{ $t('common.save') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Exam modal -->
        <AppModal :open="examModal" :title="$t('courses.createExam')" size="lg" @close="examModal = false">
            <form class="space-y-4" @submit.prevent="saveExam">
                <AppInput v-model="examForm.title" :label="$t('exams.titleField')" required id="exam-title" :error="examErrors.title" />
                <AppTextarea v-model="examForm.description" :label="$t('exams.description')" id="exam-desc" :error="examErrors.description" :rows="2" />
                <div class="grid gap-4 sm:grid-cols-3">
                    <AppInput v-model="examForm.duration_minutes" :label="$t('exams.durationMin')" type="number" id="exam-duration" :error="examErrors.duration_minutes" />
                    <AppInput v-model="examForm.pass_percentage" :label="$t('exams.passPercent')" type="number" id="exam-pass" :error="examErrors.pass_percentage" />
                    <AppInput v-model="examForm.max_attempts" :label="$t('exams.maxAttempts')" type="number" id="exam-max" :error="examErrors.max_attempts" />
                </div>
                <div class="flex justify-end gap-2"><AppButton variant="outline" @click="examModal = false">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" :loading="examBusy">{{ $t('common.create') }}</AppButton></div>
            </form>
        </AppModal>

        <!-- Enroll student modal -->
        <AppModal :open="enrollModal" :title="$t('courses.enrollStudent')" size="sm" @close="enrollModal = false">
            <form class="space-y-4" @submit.prevent="doEnroll">
                <AppSelect
                    v-model="selectedStudent"
                    :label="$t('common.student')"
                    :options="allStudents"
                    id="course-enroll-student"
                    :placeholder="$t('common.selectStudent')"
                    :error="enrollError.student_id"
                />
                <div class="flex justify-end gap-2">
                    <AppButton variant="outline" :disabled="enrollBusy" @click="enrollModal = false">{{ $t('common.cancel') }}</AppButton>
                    <AppButton type="submit" :loading="enrollBusy" :disabled="!selectedStudent">{{ $t('students.enroll') }}</AppButton>
                </div>
            </form>
        </AppModal>

        <ConfirmDialog
            :open="Boolean(confirmTarget)"
            :title="confirmTitle"
            :message="confirmMessage"
            :confirm-text="$t('common.confirm')"
            :loading="confirmBusy"
            @close="confirmTarget = null"
            @confirm="runDelete"
        />
    </div>
</template>
