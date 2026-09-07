<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalShell from './PortalShell.vue';

const { t } = useI18n();

const nav = computed(() => [
    { label: t('nav.dashboard'), to: '/assistant', icon: 'home' },
    { label: t('nav.students'), to: '/assistant/students', icon: 'users' },
    { label: t('nav.enrollStudents'), to: '/assistant/enroll', icon: 'layers' },
    { label: t('nav.notifications'), to: '/assistant/notifications', icon: 'bell' },
    { label: t('nav.profile'), to: '/assistant/profile', icon: 'user' },
]);
</script>

<template>
    <PortalShell :brand="$t('app.brand')" :subtitle="$t('app.assistantPortal')" :nav="nav" :role-label="$t('app.roleAssistant')">
        <slot />
    </PortalShell>
</template>
