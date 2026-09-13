<script setup>
import Icon from '@/components/ui/Icon.vue';

const steps = [
    { num: '01', titleKey: 'landing.journeyStep1Title', descKey: 'landing.journeyStep1Desc', icon: 'book' },
    { num: '02', titleKey: 'landing.journeyStep2Title', descKey: 'landing.journeyStep2Desc', icon: 'play' },
    { num: '03', titleKey: 'landing.journeyStep3Title', descKey: 'landing.journeyStep3Desc', icon: 'layers' },
    { num: '04', titleKey: 'landing.journeyStep4Title', descKey: 'landing.journeyStep4Desc', icon: 'clipboard' },
    { num: '05', titleKey: 'landing.journeyStep5Title', descKey: 'landing.journeyStep5Desc', icon: 'trophy' },
    { num: '06', titleKey: 'landing.journeyStep6Title', descKey: 'landing.journeyStep6Desc', icon: 'chart' },
];
</script>

<template>
    <section class="relative bg-white py-20 sm:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="mx-auto max-w-3xl text-center">
                <span class="text-xs font-bold uppercase tracking-widest text-terracotta-700">
                    {{ $t('landing.journeyTag') }}
                </span>
                <h2 class="mt-3 font-display text-3xl font-bold tracking-tight text-ink-900 sm:text-4xl lg:text-5xl" dir="auto">
                    {{ $t('landing.journeyTitle') }}
                </h2>
                <p class="mt-4 text-lg text-ink-600" dir="auto">
                    {{ $t('landing.journeySubtitle') }}
                </p>
            </div>

            <!-- 6-Step Pathway Cards -->
            <div class="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="s in steps"
                    :key="s.num"
                    class="group relative overflow-hidden rounded-2xl border border-ink-200/80 bg-parchment-50/60 p-6 shadow-sm transition-all duration-300 hover:border-terracotta-300 hover:bg-white hover:shadow-md"
                >
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-2xl font-bold text-terracotta-600">
                            {{ s.num }}
                        </span>
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-terracotta-100 text-terracotta-700 transition-colors group-hover:bg-terracotta-600 group-hover:text-white">
                            <Icon :name="s.icon" :size="20" />
                        </div>
                    </div>

                    <h3 class="mt-5 font-display text-xl font-bold text-ink-900" dir="auto">
                        {{ $t(s.titleKey) }}
                    </h3>

                    <p class="mt-2 text-sm leading-relaxed text-ink-600" dir="auto">
                        {{ $t(s.descKey) }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
