<script setup>
import { useI18n } from 'vue-i18n';
import { SUPPORTED, LABELS, setLocale } from '@/i18n';

const { locale } = useI18n();

function toggle() {
    const next = locale.value === 'ar' ? 'en' : 'ar';
    set(next);
}

function set(lang) {
    setLocale(lang);
    locale.value = lang;
}
</script>

<template>
    <div>
        <!-- Compact mobile toggle to save header horizontal space -->
        <button
            type="button"
            class="sm:hidden inline-flex items-center justify-center rounded-lg border border-ink-200 bg-white px-2 py-1 text-xs font-bold text-ink-700 shadow-xs hover:bg-ink-50 transition"
            :title="locale === 'ar' ? 'Switch to English' : 'التغيير إلى العربية'"
            @click="toggle"
        >
            <span class="text-terracotta-600 font-extrabold me-1 text-[11px]">🌐</span>
            <span>{{ locale === 'ar' ? 'EN' : 'عربي' }}</span>
        </button>

        <!-- Desktop full switcher -->
        <div class="hidden sm:inline-flex items-center overflow-hidden rounded-lg border border-ink-200 bg-white">
            <button
                v-for="code in SUPPORTED"
                :key="code"
                type="button"
                class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide transition"
                :class="locale === code ? 'bg-terracotta-600 text-white' : 'text-ink-500 hover:bg-ink-50 hover:text-ink-900'"
                :aria-pressed="locale === code"
                @click="set(code)"
            >
                {{ LABELS[code] }}
            </button>
        </div>
    </div>
</template>
