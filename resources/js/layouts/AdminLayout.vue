<script setup>
import PortalShell from './PortalShell.vue';

const nav = [
    { label: 'Dashboard', to: '/admin', icon: 'home' },
    { label: 'Teachers', to: '/admin/teachers', icon: 'user' },
    { label: 'Students', to: '/admin/students', icon: 'users' },
    { label: 'Notifications', to: '/admin/notifications', icon: 'bell' },
    { label: 'Profile', to: '/admin/profile', icon: 'user' },
];
</script>

<template>
    <PortalShell brand="Atlas Academy" subtitle="System Admin" :nav="nav" role-label="Admin">
        <slot />
    </PortalShell>
</template>
