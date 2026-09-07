<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalShell from './PortalShell.vue';

const { t } = useI18n();

const nav = computed(() => [
    { label: t('nav.dashboard'), to: '/admin', icon: 'home' },
    { label: t('dashboard.teachers'), to: '/admin/teachers', icon: 'user' },
    { label: t('nav.students'), to: '/admin/students', icon: 'users' },
    { label: t('nav.notifications'), to: '/admin/notifications', icon: 'bell' },
    { label: t('nav.profile'), to: '/admin/profile', icon: 'user' },
]);
</script>

<template>
    <PortalShell :brand="$t('app.brand')" :subtitle="$t('app.adminPortal')" :nav="nav" :role-label="$t('app.roleAdmin')">
        <slot />
    </PortalShell>
</template>
