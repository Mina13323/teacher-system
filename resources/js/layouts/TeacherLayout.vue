<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalShell from './PortalShell.vue';

const { t } = useI18n();

const nav = computed(() => [
    { label: t('nav.dashboard'), to: '/teacher', icon: 'home' },
    { label: t('nav.students'), to: '/teacher/students', icon: 'users' },
    { label: t('nav.assistants'), to: '/teacher/assistants', icon: 'user' },
    { label: t('nav.courses'), to: '/teacher/courses', icon: 'book' },
    { label: t('nav.competitions'), to: '/teacher/competitions', icon: 'trophy' },
    { label: t('nav.analytics'), to: '/teacher/analytics', icon: 'chart' },
    { label: t('nav.integrity'), to: '/teacher/integrity', icon: 'shield' },
    { label: t('nav.notifications'), to: '/teacher/notifications', icon: 'bell' },
    { label: t('nav.profile'), to: '/teacher/profile', icon: 'user' },
]);
</script>

<template>
    <PortalShell :brand="$t('app.brand')" :subtitle="$t('app.teacherPortal')" :nav="nav" :role-label="$t('app.roleTeacher')">
        <slot />
    </PortalShell>
</template>
