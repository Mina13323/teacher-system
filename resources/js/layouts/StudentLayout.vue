<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalShell from './PortalShell.vue';

const { t } = useI18n();

const nav = computed(() => [
    { label: t('nav.dashboard'), to: '/student', icon: 'home' },
    { label: t('dashboard.myCourses'), to: '/student/courses', icon: 'book' },
    { label: t('nav.exams'), to: '/student/exams', icon: 'clipboard' },
    { label: t('nav.competitions'), to: '/student/competitions', icon: 'trophy' },
    { label: t('nav.analytics'), to: '/student/analytics', icon: 'chart' },
    { label: t('nav.notifications'), to: '/student/notifications', icon: 'bell' },
    { label: t('nav.profile'), to: '/student/profile', icon: 'user' },
]);
</script>

<template>
    <PortalShell :brand="$t('app.brand')" :subtitle="$t('app.studentPortal')" :nav="nav" :role-label="$t('app.roleStudent')">
        <slot />
    </PortalShell>
</template>
