<script setup>
import Icon from '@/components/ui/Icon.vue';

const timelineFlow = [
    { step: '01', titleKey: 'landing.timelineStep1', icon: 'clock', desc: 'Ancient Foundations & Civilizations' },
    { step: '02', titleKey: 'landing.timelineStep2', icon: 'sparkles', desc: 'Catalyst Turning Points' },
    { step: '03', titleKey: 'landing.timelineStep3', icon: 'user', desc: 'Human Choice & Conflict' },
    { step: '04', titleKey: 'landing.timelineStep4', icon: 'globe', desc: 'Systemic Transformation' },
    { step: '05', titleKey: 'landing.timelineStep5', icon: 'target', desc: 'Modern Geopolitical Order' },
];

const historyTopics = [
    {
        titleKey: 'landing.histItem1Title',
        descKey: 'landing.histItem1Desc',
        era: 'ANCIENT WORLD',
        icon: 'book',
    },
    {
        titleKey: 'landing.histItem2Title',
        descKey: 'landing.histItem2Desc',
        era: 'MEDIEVAL & EARLY MODERN',
        icon: 'compass',
    },
    {
        titleKey: 'landing.histItem3Title',
        descKey: 'landing.histItem3Desc',
        era: 'MODERN ERA',
        icon: 'globe',
    },
    {
        titleKey: 'landing.histItem4Title',
        descKey: 'landing.histItem4Desc',
        era: 'DOCUMENTARY INQUIRY',
        icon: 'clipboard',
    },
];
</script>

<template>
    <section id="history" class="relative border-t border-ink-200/80 bg-ink-950 py-20 text-white sm:py-28">
        <!-- Ambient Radial Glow -->
        <div class="pointer-events-none absolute left-1/2 top-0 h-96 w-[600px] -translate-x-1/2 rounded-full bg-terracotta-900/10 blur-3xl"></div>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="mx-auto max-w-3xl text-center">
                <span class="inline-flex items-center gap-2 rounded-md border border-amber-800/60 bg-amber-950/60 px-3 py-1 text-xs font-bold uppercase tracking-widest text-amber-300">
                    <Icon name="clock" :size="14" class="text-amber-400" />
                    {{ $t('landing.historyTag') }}
                </span>
                <h2 class="mt-4 font-display text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl" dir="auto">
                    {{ $t('landing.historyTitle') }}
                </h2>
                <p class="mt-4 text-lg text-ink-300" dir="auto">
                    {{ $t('landing.historySubtitle') }}
                </p>
            </div>

            <!-- Timeline Progression Bar (Past -> Today) -->
            <div class="mt-14 rounded-2xl border border-ink-800 bg-ink-900/90 p-6 shadow-2xl backdrop-blur sm:p-8">
                <div class="mb-6 flex items-center justify-between border-b border-ink-800 pb-4">
                    <h3 class="font-mono text-xs font-bold uppercase tracking-widest text-terracotta-400">
                        CHRONOLOGICAL CAUSALITY FLOW
                    </h3>
                    <span class="text-xs text-ink-400">PAST → TODAY</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-5">
                    <div
                        v-for="(item, index) in timelineFlow"
                        :key="item.step"
                        class="relative flex flex-col items-center rounded-xl border border-ink-800 bg-ink-950/70 p-4 text-center transition-all duration-300 hover:border-terracotta-500/50 hover:bg-ink-950"
                    >
                        <span class="font-mono text-xs font-bold text-terracotta-400">
                            {{ item.step }}
                        </span>
                        <div class="mt-2 flex h-9 w-9 items-center justify-center rounded-full bg-ink-800 text-amber-400">
                            <Icon :name="item.icon" :size="18" />
                        </div>
                        <h4 class="mt-3 text-sm font-bold text-white" dir="auto">
                            {{ $t(item.titleKey) }}
                        </h4>
                        <p class="mt-1 text-[11px] text-ink-400">
                            {{ item.desc }}
                        </p>

                        <!-- Connecting Arrow (desktop) -->
                        <div v-if="index < timelineFlow.length - 1" class="hidden sm:absolute sm:-end-3 sm:top-1/2 sm:z-10 sm:-translate-y-1/2 sm:block text-ink-600">
                            →
                        </div>
                    </div>
                </div>
            </div>

            <!-- Historical Eras Grid -->
            <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="topic in historyTopics"
                    :key="topic.era"
                    class="group rounded-2xl border border-ink-800 bg-ink-900/60 p-6 transition-all duration-300 hover:border-amber-500/40 hover:bg-ink-900"
                >
                    <span class="font-mono text-[10px] font-bold tracking-wider text-amber-400">
                        {{ topic.era }}
                    </span>
                    <div class="mt-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-950/80 text-amber-400 group-hover:bg-amber-500 group-hover:text-ink-950 transition-colors">
                            <Icon :name="topic.icon" :size="20" />
                        </div>
                        <h3 class="font-display text-lg font-bold text-white group-hover:text-amber-300" dir="auto">
                            {{ $t(topic.titleKey) }}
                        </h3>
                    </div>
                    <p class="mt-3 text-sm leading-relaxed text-ink-300" dir="auto">
                        {{ $t(topic.descKey) }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
