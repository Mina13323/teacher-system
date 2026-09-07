<script setup>
import { useI18n } from 'vue-i18n';
import { SUPPORTED, LABELS, setLocale } from '@/i18n';

const { locale } = useI18n();

function set(lang) {
    setLocale(lang);
    locale.value = lang;
}
</script>

<template>
    <div class="inline-flex items-center overflow-hidden rounded-lg border border-ink-200 bg-white">
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
</template>
