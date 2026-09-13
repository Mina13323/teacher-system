<script setup>
import { ref } from 'vue';
import Icon from '@/components/ui/Icon.vue';

/**
 * CONFIGURABLE MARKETING VIDEO SOURCE
 * 
 * Project owner can update VIDEO_CONFIG to point to:
 * - YouTube embed URL: e.g. 'https://www.youtube-nocookie.com/embed/YOUR_VIDEO_ID?autoplay=1'
 * - Local MP4 video asset: e.g. '/assets/videos/landing_intro.mp4'
 * 
 * type options: 'youtube' | 'local'
 */
const VIDEO_CONFIG = ref({
    type: 'youtube', // 'youtube' | 'local'
    source: 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1',
    posterText: 'Geography & History Beyond the Classroom',
    durationText: '03:45',
});

const isPlaying = ref(false);

function playVideo() {
    isPlaying.value = true;
}

function closeVideo() {
    isPlaying.value = false;
}
</script>

<template>
    <div class="group relative mx-auto w-full max-w-4xl">
        <!-- Frame Outer Glow / Border -->
        <div class="relative overflow-hidden rounded-2xl border border-ink-200/80 bg-ink-900 shadow-2xl transition-all duration-500 hover:shadow-terracotta-900/10">
            <!-- Top Editorial Bar -->
            <div class="flex items-center justify-between border-b border-ink-800 bg-ink-950/90 px-4 py-2.5 text-xs text-ink-300">
                <div class="flex items-center gap-2 font-mono text-[11px] tracking-wider text-terracotta-400">
                    <Icon name="compass" :size="14" />
                    <span>30°02'N 31°14'E</span>
                    <span class="text-ink-600">•</span>
                    <span>ATLAS ACADEMIC OVERVIEW</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="rounded bg-terracotta-950 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-terracotta-300">
                        {{ VIDEO_CONFIG.durationText }}
                    </span>
                </div>
            </div>

            <!-- Video Display Container -->
            <div class="relative aspect-video w-full bg-ink-950">
                <!-- Played State (Embed/Player) -->
                <template v-if="isPlaying">
                    <iframe
                        v-if="VIDEO_CONFIG.type === 'youtube'"
                        :src="VIDEO_CONFIG.source"
                        class="h-full w-full border-0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                        title="Platform Video Overview"
                    ></iframe>
                    <video
                        v-else
                        :src="VIDEO_CONFIG.source"
                        controls
                        autoplay
                        class="h-full w-full object-cover"
                    ></video>
                </template>

                <!-- Poster State (Custom Thumbnail & Play Overlay) -->
                <template v-else>
                    <!-- Background Visual Field (Cartographic Pattern + Gradient) -->
                    <div class="absolute inset-0 bg-[radial-gradient(#d95a2b22_1px,transparent_1px)] [background-size:16px_16px]"></div>
                    <div class="absolute inset-0 bg-gradient-to-t from-ink-950 via-ink-900/80 to-ink-950/40"></div>

                    <!-- Subtle Contour Lines Graphic -->
                    <svg class="absolute inset-0 h-full w-full opacity-20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M-100 100 Q 150 300 400 100 T 900 200" stroke="#d95a2b" stroke-width="1.5" fill="none" />
                        <path d="M-50 200 Q 200 400 500 150 T 1000 300" stroke="#d95a2b" stroke-width="1" fill="none" />
                    </svg>

                    <!-- Poster Content Overlay -->
                    <div class="relative flex h-full flex-col items-center justify-center p-6 text-center">
                        <!-- Pulse Play Button -->
                        <button
                            type="button"
                            @click="playVideo"
                            class="group/btn relative flex h-20 w-20 items-center justify-center rounded-full bg-terracotta-600 text-white shadow-xl transition-all duration-300 hover:scale-110 hover:bg-terracotta-500 focus:outline-none focus:ring-4 focus:ring-terracotta-400/40"
                            aria-label="Play Overview Video"
                        >
                            <span class="absolute inset-0 animate-ping rounded-full bg-terracotta-500 opacity-30"></span>
                            <Icon name="play" :size="32" class="ms-1 fill-current" />
                        </button>

                        <h3 class="mt-5 font-display text-xl font-bold tracking-tight text-white sm:text-2xl" dir="auto">
                            {{ $t('landing.videoTitle') }}
                        </h3>
                        <p class="mt-1.5 max-w-md text-sm text-ink-300" dir="auto">
                            {{ $t('landing.videoSubtitle') }}
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
