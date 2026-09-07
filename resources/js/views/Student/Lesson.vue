<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useAsync } from '@/composables/useAsync';
import { student, toList } from '@/api';
import { useToast } from '@/composables/toast';
import ProtectedPlayer from '@/components/video/ProtectedPlayer.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import Icon from '@/components/ui/Icon.vue';

const route = useRoute();
const toast = useToast();
const lessonId = route.params.id;

const progress = ref(null);
const videos = ref([]);
const course = ref(null);
const activeVideo = ref(null);

const { loading, error, run } = useAsync(async () => {
    const [p, vRes, courseRes] = await Promise.all([
        student.lessonProgress(lessonId).catch(() => null),
        student.lessonVideos(lessonId).catch(() => toList(null)),
        route.query.course ? student.course(route.query.course).catch(() => null) : Promise.resolve(null),
    ]);
    progress.value = p;
    videos.value = toList(vRes).items;
    course.value = courseRes;
});

async function markComplete() {
    try {
        await student.saveLessonProgress(lessonId, { progress_percentage: 100, last_position_seconds: 0, completed: true });
        toast.success('Lesson marked complete.');
        if (progress.value) {
            progress.value.completed = true;
            progress.value.progress_percentage = 100;
        }
    } catch (e) {
        toast.error(e.message);
    }
}

function selectVideo(v) {
    activeVideo.value = null;
    // wait a tick so the player unmounts & clears its session before switching.
    setTimeout(() => { activeVideo.value = v; }, 0);
}

onMounted(() => run());
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link :to="route.query.course ? `/student/courses/${route.query.course}` : '/student/courses'" class="text-sm font-medium text-terracotta-600 hover:underline">← Back to course</router-link>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900">{{ progress?.lesson?.title || 'Lesson' }}</h1>
                <AppBadge :tone="progress?.completed ? 'success' : 'warning'">{{ progress?.completed ? 'Completed' : 'In progress' }}</AppBadge>
            </div>
            <p v-if="progress?.lesson?.description" class="mt-1 text-ink-600">{{ progress.lesson.description }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <div v-if="!videos.length" class="space-y-6">
                <EmptyState icon="play" title="No videos yet" message="There are no published videos in this lesson yet." />
            </div>
            <div v-else class="grid gap-6 lg:grid-cols-3">
                <!-- Video list -->
                <div class="space-y-2 lg:col-span-1">
                    <h2 class="mb-3 text-sm font-semibold text-ink-700">Videos in this lesson</h2>
                    <button
                        v-for="(v, i) in videos"
                        :key="v.id"
                        class="flex w-full items-center gap-3 rounded-xl border p-3 text-left transition"
                        :class="activeVideo?.id === v.id ? 'border-terracotta-300 bg-terracotta-50' : 'border-ink-100 bg-white hover:border-ink-200 hover:bg-ink-50'"
                        @click="selectVideo(v)"
                    >
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600"><Icon name="play" :size="18" /></div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink-800">{{ v.title }}</p>
                            <p class="text-xs text-ink-400">{{ v.duration ? `${v.duration}s` : 'Video' }}</p>
                        </div>
                    </button>
                    <div class="pt-2">
                        <AppButton variant="outline" :disabled="progress?.completed" @click="markComplete">
                            {{ progress?.completed ? 'Completed' : 'Mark complete' }}
                        </AppButton>
                    </div>
                </div>

                <!-- Player -->
                <div class="lg:col-span-2">
                    <AppCard :title="activeVideo?.title || 'Video'" :padded="false" class="overflow-hidden">
                        <ProtectedPlayer v-if="activeVideo" :video-id="activeVideo.id" :watermark-text="progress?.lesson?.title || ''" />
                        <div v-else class="flex h-72 flex-col items-center justify-center rounded-b-xl bg-ink-50 text-ink-400">
                            <Icon name="play" :size="36" />
                            <p class="mt-2 text-sm">Select a video to watch.</p>
                        </div>
                    </AppCard>
                </div>
            </div>
        </template>
    </div>
</template>
