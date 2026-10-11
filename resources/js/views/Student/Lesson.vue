<script setup>
import { computed, ref, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { student } from '@/api';
import { useToast } from '@/composables/toast';
import { createLessonPageLoader } from '@/utils/lessonPage';
import ProtectedPlayer from '@/components/video/ProtectedPlayer.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import Icon from '@/components/ui/Icon.vue';
import LessonExtras from '@/components/learning/LessonExtras.vue';

const { t } = useI18n();
const route = useRoute();
const toast = useToast();
/** The route is reused when only the lesson id changes, so read it live. */
const lessonId = computed(() => String(route.params.id));

const progress = ref(null);
const lesson = ref(null);
const attachments = ref([]);
const videos = ref([]);
const activeVideo = ref(null);
/** The student's bookmark on this lesson: { id } or null; undefined = unknown. */
const bookmark = ref(undefined);
/** Which lesson the state above belongs to. */
const loadedLessonId = ref(null);

// One request for lesson, attachments, progress, video list and bookmark.
// (The page only needs the course id for its back link, from the URL.)
const pageLoader = createLessonPageLoader({ api: student });

const loading = ref(true);
const error = ref(null);

async function loadLesson() {
    activeVideo.value = null;
    loading.value = true;
    error.value = null;
    try {
        const page = await pageLoader.load(lessonId.value);
        // null: the student has moved on to another lesson meanwhile; that
        // lesson's own load settles the page.
        if (!page) return;
        lesson.value = page.lesson;
        attachments.value = page.attachments;
        progress.value = page.progress;
        videos.value = page.videos;
        bookmark.value = page.bookmark;
        loadedLessonId.value = page.lessonId;
        loading.value = false;
    } catch (e) {
        // Nothing of another lesson stays on screen under this one's error.
        lesson.value = null;
        attachments.value = [];
        progress.value = null;
        videos.value = [];
        bookmark.value = undefined;
        loadedLessonId.value = null;
        error.value = e;
        loading.value = false;
    }
}

async function markComplete() {
    const id = lessonId.value;
    try {
        await student.saveLessonProgress(id, { progress_percentage: 100, last_position_seconds: 0, completed: true });
        toast.success(t('lesson.markedComplete'));
        if (progress.value && loadedLessonId.value === id) {
            progress.value.completed = true;
            progress.value.progress_percentage = 100;
        }
    } catch (e) {
        toast.error(e.message);
    }
}

async function downloadAttachment(att) {
    try {
        await student.downloadLessonAttachment(att.id, att.title);
    } catch (e) {
        toast.error(e.message);
    }
}

function selectVideo(v) {
    activeVideo.value = null;
    const forLesson = loadedLessonId.value;
    // wait a tick so the player unmounts & clears its session before switching.
    setTimeout(() => {
        if (loadedLessonId.value === forLesson && lessonId.value === forLesson) activeVideo.value = v;
    }, 0);
}

onMounted(loadLesson);
watch(lessonId, (next, prev) => {
    if (next !== prev) loadLesson();
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link :to="route.query.course ? `/student/courses/${route.query.course}` : '/student/courses'" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('lesson.backToCourse') }}</router-link>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900" dir="auto">{{ lesson?.title || progress?.lesson?.title || $t('nav.lesson') }}</h1>
                <AppBadge :tone="progress?.completed ? 'success' : 'warning'">{{ progress?.completed ? $t('status.completed') : $t('status.in_progress') }}</AppBadge>
            </div>
            <p v-if="lesson?.description || progress?.lesson?.description" class="mt-1 text-ink-600" dir="auto">{{ lesson?.description || progress?.lesson?.description }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <!-- Lesson body content (authored by the teacher) -->
            <section v-if="lesson?.content" class="rounded-xl border border-ink-100 bg-white p-6 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-ink-700">{{ $t('lesson.contentTitle') }}</h2>
                <div class="whitespace-pre-wrap text-ink-800 leading-relaxed" dir="auto">{{ lesson.content }}</div>
            </section>

            <div v-if="!videos.length" class="space-y-6">
                <EmptyState icon="play" :title="$t('lesson.noVideosTitle')" :message="$t('lesson.noVideosMessage')" />

                <!-- PHASE 4: notes, Q&A and bookmark -->
                <LessonExtras
                    v-if="loadedLessonId === lessonId"
                    :key="lessonId"
                    class="mt-6"
                    :lesson-id="lessonId"
                    :bookmark="bookmark"
                />

                <!-- Lesson attachments -->
                <div v-if="attachments.length" class="mt-4 rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
                    <p class="text-sm font-semibold text-ink-800">{{ $t('lesson.attachmentsTitle') }}</p>
                    <ul class="mt-2 space-y-1">
                        <li v-for="att in attachments" :key="att.id">
                            <button type="button" class="flex items-center gap-2 text-sm text-terracotta-600 hover:underline" @click="downloadAttachment(att)">
                                📎 <span dir="auto">{{ att.title }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
            <div v-else class="grid gap-6 lg:grid-cols-3">
                <!-- Video list -->
                <div class="space-y-2 lg:col-span-1">
                    <h2 class="mb-3 text-sm font-semibold text-ink-700">{{ $t('lesson.videosInLesson') }}</h2>
                    <button
                        v-for="v in videos"
                        :key="v.id"
                        class="flex w-full items-center gap-3 rounded-xl border p-3 text-start transition"
                        :class="activeVideo?.id === v.id ? 'border-terracotta-300 bg-terracotta-50' : 'border-ink-100 bg-white hover:border-ink-200 hover:bg-ink-50'"
                        @click="selectVideo(v)"
                    >
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600"><Icon name="play" :size="18" /></div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink-800" dir="auto">{{ v.title }}</p>
                            <p class="text-xs text-ink-400">{{ v.duration ? `${v.duration}${$t('common.minutesShort')}` : $t('common.video') }}</p>
                        </div>
                    </button>
                    <div class="pt-2">
                        <AppButton variant="outline" :disabled="progress?.completed" @click="markComplete">
                            {{ progress?.completed ? $t('lesson.completedLabel') : $t('lesson.markComplete') }}
                        </AppButton>
                    </div>
                </div>

                <!-- Player -->
                <div class="lg:col-span-2">
                    <AppCard :title="activeVideo?.title || $t('common.video')" :padded="false" class="overflow-hidden">
                        <ProtectedPlayer v-if="activeVideo" :video-id="activeVideo.id" :watermark-text="lesson?.title || progress?.lesson?.title || ''" />
                        <div v-else class="flex h-72 flex-col items-center justify-center rounded-b-xl bg-ink-50 text-ink-400">
                            <Icon name="play" :size="36" />
                            <p class="mt-2 text-sm">{{ $t('lesson.selectVideo') }}</p>
                        </div>
                    </AppCard>
                </div>
            </div>
        </template>
    </div>
</template>
