<script setup>
import Icon from '@/components/ui/Icon.vue';

const steps = [
    { num: '01', titleKey: 'landing.howStep1', descKey: 'landing.howStep1Desc', icon: 'compass' },
    { num: '02', titleKey: 'landing.howStep2', descKey: 'landing.howStep2Desc', icon: 'user' },
    { num: '03', titleKey: 'landing.howStep3', descKey: 'landing.howStep3Desc', icon: 'book' },
    { num: '04', titleKey: 'landing.howStep4', descKey: 'landing.howStep4Desc', icon: 'trophy' },
];
</script>

<template>
    <section class="relative border-t border-ink-200/60 bg-ink-900 py-20 text-white sm:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mx-auto max-w-3xl text-center">
                <span class="text-xs font-bold uppercase tracking-widest text-terracotta-400">
                    {{ $t('landing.howTag') }}
                </span>
                <h2 class="mt-3 font-display text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl" dir="auto">
                    {{ $t('landing.howTitle') }}
                </h2>
                <p class="mt-4 text-lg text-ink-300" dir="auto">
                    {{ $t('landing.howSubtitle') }}
                </p>
            </div>

            <!-- Steps Grid -->
            <div class="mt-16 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="(s, idx) in steps"
                    :key="s.num"
                    class="relative rounded-2xl border border-ink-800 bg-ink-950/70 p-6 text-center shadow-md transition-all duration-300 hover:border-terracotta-500/50"
                >
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-terracotta-600 to-terracotta-700 text-white shadow-lg">
                        <Icon :name="s.icon" :size="24" />
                    </div>

                    <span class="mt-4 inline-block font-mono text-xs font-bold text-terracotta-400">
                        STEP {{ s.num }}
                    </span>

                    <h3 class="mt-2 font-display text-xl font-bold text-white" dir="auto">
                        {{ $t(s.titleKey) }}
                    </h3>

                    <p class="mt-2 text-sm leading-relaxed text-ink-300" dir="auto">
                        {{ $t(s.descKey) }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
