<script setup>
import Icon from '@/components/ui/Icon.vue';
</script>

<template>
    <section id="philosophy" class="relative border-y border-ink-200/60 bg-white py-20 sm:py-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid items-center gap-12 lg:grid-cols-12 lg:gap-16">
                <!-- Left Editorial Text -->
                <div class="lg:col-span-7">
                    <span class="text-xs font-bold uppercase tracking-widest text-terracotta-700">
                        {{ $t('landing.philosophyTag') }}
                    </span>
                    <h2 class="mt-3 font-display text-3xl font-bold tracking-tight text-ink-900 sm:text-4xl lg:text-5xl" dir="auto">
                        {{ $t('landing.philosophyTitle') }}
                    </h2>
                    <p class="mt-4 text-lg font-medium leading-relaxed text-ink-700" dir="auto">
                        {{ $t('landing.philosophySubtitle') }}
                    </p>

                    <div class="mt-8 space-y-6 text-ink-600">
                        <div class="flex items-start gap-4 rounded-xl border border-ink-100 bg-parchment-50/60 p-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-terracotta-100 text-terracotta-700">
                                <Icon name="globe" :size="20" />
                            </div>
                            <p class="text-sm sm:text-base leading-relaxed" dir="auto">
                                {{ $t('landing.philosophyP1') }}
                            </p>
                        </div>

                        <div class="flex items-start gap-4 rounded-xl border border-ink-100 bg-parchment-50/60 p-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-800">
                                <Icon name="clock" :size="20" />
                            </div>
                            <p class="text-sm sm:text-base leading-relaxed" dir="auto">
                                {{ $t('landing.philosophyP2') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Right Editorial Quote Card -->
                <div class="lg:col-span-5">
                    <div class="relative rounded-2xl border border-ink-200 bg-gradient-to-br from-ink-900 to-ink-950 p-8 text-white shadow-xl">
                        <!-- Subtle Compass Background Watermark -->
                        <Icon name="compass" :size="140" class="pointer-events-none absolute -bottom-6 -end-6 text-ink-800/40" />

                        <div class="relative z-10">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-terracotta-600 text-white shadow-md">
                                <Icon name="graduationCap" :size="24" />
                            </div>

                            <blockquote class="mt-6 font-display text-lg font-medium leading-relaxed text-ink-100" dir="auto">
                                "When a student understands the connection between mountain ranges and trade routes, or how past treaties shape modern nations, learning changes from memorization to genuine discovery."
                            </blockquote>

                            <div class="mt-6 flex items-center gap-3 border-t border-ink-800 pt-4">
                                <div class="h-9 w-9 rounded-full bg-terracotta-700 flex items-center justify-center text-xs font-bold text-white">
                                    EM
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-white">El Masry Academic Standard</h4>
                                    <p class="text-xs text-ink-400">Geography & History Specialist</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
