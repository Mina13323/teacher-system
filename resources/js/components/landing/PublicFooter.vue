<script setup>
import Icon from '@/components/ui/Icon.vue';
</script>

<template>
    <footer class="border-t border-ink-200/80 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
            <div class="grid gap-10 md:grid-cols-12">
                <!-- Brand Info -->
                <div class="md:col-span-6">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-terracotta-600 text-white shadow-sm">
                            <Icon name="compass" :size="22" />
                        </div>
                        <div>
                            <span class="font-display text-lg font-bold text-ink-900" dir="auto">{{ $t('app.brand') }}</span>
                            <p class="text-xs uppercase tracking-wider text-terracotta-700" dir="auto">{{ $t('app.tagline') }}</p>
                        </div>
                    </div>
                    <p class="mt-4 max-w-md text-sm leading-relaxed text-ink-600" dir="auto">
                        {{ $t('landing.footerDesc') }}
                    </p>
                </div>

                <!-- Navigation Links -->
                <div class="md:col-span-3">
                    <h4 class="text-xs font-bold uppercase tracking-widest text-ink-900">
                        {{ $t('app.home') }}
                    </h4>
                    <ul class="mt-4 space-y-2 text-sm text-ink-600">
                        <li><a href="#philosophy" class="transition hover:text-terracotta-600">{{ $t('landing.philosophyTag') }}</a></li>
                        <li><a href="#geography" class="transition hover:text-terracotta-600">{{ $t('landing.geographyTag') }}</a></li>
                        <li><a href="#history" class="transition hover:text-terracotta-600">{{ $t('landing.historyTag') }}</a></li>
                        <li><a href="#curriculum" class="transition hover:text-terracotta-600">{{ $t('landing.coursesTitle') }}</a></li>
                    </ul>
                </div>

                <!-- Access Links -->
                <div class="md:col-span-3">
                    <h4 class="text-xs font-bold uppercase tracking-widest text-ink-900">
                        {{ $t('common.credentials') }}
                    </h4>
                    <ul class="mt-4 space-y-2 text-sm text-ink-600">
                        <li><router-link to="/login" class="transition hover:text-terracotta-600">{{ $t('auth.signIn') }}</router-link></li>
                        <li><router-link to="/login" class="transition hover:text-terracotta-600">{{ $t('landing.ctaLogin') }}</router-link></li>
                    </ul>
                </div>
            </div>

            <div class="mt-12 flex flex-col items-center justify-between border-t border-ink-100 pt-8 text-xs text-ink-500 sm:flex-row">
                <p dir="auto">
                    © {{ new Date().getFullYear() }} {{ $t('app.brand') }}. All rights reserved.
                </p>
                <p class="mt-2 sm:mt-0" dir="auto">
                    Geography & History Educational System
                </p>
            </div>
        </div>
    </footer>
</template>
