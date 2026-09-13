<script setup>
import Icon from '@/components/ui/Icon.vue';

const geoItems = [
    {
        key: 'item1',
        titleKey: 'landing.geoItem1Title',
        descKey: 'landing.geoItem1Desc',
        icon: 'map',
        tag: 'CARTOGRAPHY',
        coord: '12°34\'N 78°90\'E',
    },
    {
        key: 'item2',
        titleKey: 'landing.geoItem2Title',
        descKey: 'landing.geoItem2Desc',
        icon: 'globe',
        tag: 'PHYSICAL SYSTEMS',
        coord: '45°12\'S 123°45\'W',
    },
    {
        key: 'item3',
        titleKey: 'landing.geoItem3Title',
        descKey: 'landing.geoItem3Desc',
        icon: 'users',
        tag: 'HUMAN DYNAMICS',
        coord: '51°30\'N 0°07\'W',
    },
    {
        key: 'item4',
        titleKey: 'landing.geoItem4Title',
        descKey: 'landing.geoItem4Desc',
        icon: 'target',
        tag: 'GEOPOLITICS',
        coord: '35°40\'N 139°45\'E',
    },
];
</script>

<template>
    <section id="geography" class="relative overflow-hidden bg-parchment-50 py-20 sm:py-28">
        <!-- Contour Line Top Pattern -->
        <div class="absolute inset-0 opacity-15 bg-[radial-gradient(#d95a2b15_1px,transparent_1px)] [background-size:24px_24px]"></div>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="mx-auto max-w-3xl text-center">
                <span class="inline-flex items-center gap-2 rounded-md border border-terracotta-200 bg-terracotta-50 px-3 py-1 text-xs font-bold uppercase tracking-widest text-terracotta-800">
                    <Icon name="compass" :size="14" class="text-terracotta-600" />
                    {{ $t('landing.geographyTag') }}
                </span>
                <h2 class="mt-4 font-display text-3xl font-bold tracking-tight text-ink-900 sm:text-4xl lg:text-5xl" dir="auto">
                    {{ $t('landing.geographyTitle') }}
                </h2>
                <p class="mt-4 text-lg text-ink-600" dir="auto">
                    {{ $t('landing.geographySubtitle') }}
                </p>
            </div>

            <!-- Geography Domain Grid -->
            <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="item in geoItems"
                    :key="item.key"
                    class="group relative overflow-hidden rounded-2xl border border-ink-200/90 bg-white p-6 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-terracotta-300 hover:shadow-lg"
                >
                    <!-- Coordinate Top Ribbon -->
                    <div class="flex items-center justify-between border-b border-ink-100 pb-3">
                        <span class="font-mono text-[11px] font-semibold text-terracotta-700 tracking-wider">
                            {{ item.coord }}
                        </span>
                        <span class="rounded bg-parchment-100 px-2 py-0.5 font-mono text-[10px] font-bold text-ink-600">
                            {{ item.tag }}
                        </span>
                    </div>

                    <!-- Icon & Title -->
                    <div class="mt-5 flex items-center gap-3">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-terracotta-50 text-terracotta-600 transition-colors duration-300 group-hover:bg-terracotta-600 group-hover:text-white">
                            <Icon :name="item.icon" :size="22" />
                        </div>
                        <h3 class="font-display text-lg font-bold text-ink-900 group-hover:text-terracotta-700" dir="auto">
                            {{ $t(item.titleKey) }}
                        </h3>
                    </div>

                    <!-- Description -->
                    <p class="mt-3 text-sm leading-relaxed text-ink-600" dir="auto">
                        {{ $t(item.descKey) }}
                    </p>

                    <!-- Decorative Map Grid Corner -->
                    <div class="mt-4 flex items-center gap-1.5 text-xs text-ink-400 group-hover:text-terracotta-600">
                        <span>SPATIAL ANALYSIS</span>
                        <Icon name="arrowRight" :size="14" />
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
