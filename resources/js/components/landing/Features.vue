<script setup>
import Icon from '@/components/ui/Icon.vue';

const features = [
    { titleKey: 'landing.feat1Title', descKey: 'landing.feat1Desc', icon: 'book' },
    { titleKey: 'landing.feat2Title', descKey: 'landing.feat2Desc', icon: 'play' },
    { titleKey: 'landing.feat3Title', descKey: 'landing.feat3Desc', icon: 'clipboard' },
    { titleKey: 'landing.feat4Title', descKey: 'landing.feat4Desc', icon: 'trophy' },
    { titleKey: 'landing.feat5Title', descKey: 'landing.feat5Desc', icon: 'shield' },
    { titleKey: 'landing.feat6Title', descKey: 'landing.feat6Desc', icon: 'chart' },
];
</script>

<template>
    <section class="relative border-t border-ink-200/60 bg-parchment-50 py-20 sm:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="mx-auto max-w-3xl text-center">
                <span class="text-xs font-bold uppercase tracking-widest text-terracotta-700">
                    {{ $t('landing.featuresTag') }}
                </span>
                <h2 class="mt-3 font-display text-3xl font-bold tracking-tight text-ink-900 sm:text-4xl lg:text-5xl" dir="auto">
                    {{ $t('landing.featuresTitle') }}
                </h2>
                <p class="mt-4 text-lg text-ink-600" dir="auto">
                    {{ $t('landing.featuresSubtitle') }}
                </p>
            </div>

            <!-- Feature Grid -->
            <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="f in features"
                    :key="f.titleKey"
                    class="rounded-2xl border border-ink-200/90 bg-white p-6 shadow-sm transition-all duration-300 hover:border-terracotta-300 hover:shadow-md"
                >
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-terracotta-50 text-terracotta-600">
                        <Icon :name="f.icon" :size="22" />
                    </div>
                    <h3 class="mt-4 font-display text-lg font-bold text-ink-900" dir="auto">
                        {{ $t(f.titleKey) }}
                    </h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-600" dir="auto">
                        {{ $t(f.descKey) }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
