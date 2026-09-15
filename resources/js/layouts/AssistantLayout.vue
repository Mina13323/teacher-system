<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalShell from './PortalShell.vue';

const { t } = useI18n();

// An Assistant has the same operational capabilities as the Teacher. The
// content/exam/competition/analytics/integrity sections are the shared staff
// views mounted under /teacher, so the nav points there rather than duplicating
// them under /assistant.
const nav = computed(() => [
    { label: t('nav.dashboard'), to: '/assistant', icon: 'home' },
    { label: t('nav.students'), to: '/assistant/students', icon: 'users' },
    { label: t('nav.enrollStudents'), to: '/assistant/enroll', icon: 'layers' },
    { label: t('nav.courses'), to: '/teacher/courses', icon: 'book' },
    { label: t('nav.competitions'), to: '/teacher/competitions', icon: 'trophy' },
    { label: t('nav.analytics'), to: '/teacher/analytics', icon: 'chart' },
    { label: t('nav.integrity'), to: '/teacher/integrity', icon: 'shield' },
    { label: t('nav.notifications'), to: '/assistant/notifications', icon: 'bell' },
    { label: t('nav.profile'), to: '/assistant/profile', icon: 'user' },
]);
</script>

<template>
    <PortalShell :brand="$t('app.brand')" :subtitle="$t('app.assistantPortal')" :nav="nav" :role-label="$t('app.roleAssistant')">
        <router-view />
    </PortalShell>
</template>
