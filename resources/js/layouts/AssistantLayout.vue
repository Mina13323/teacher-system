<script setup>
import PortalShell from './PortalShell.vue';

const nav = [
    { label: 'Dashboard', to: '/assistant', icon: 'home' },
    { label: 'Students', to: '/assistant/students', icon: 'users' },
    { label: 'Enroll Students', to: '/assistant/enroll', icon: 'layers' },
    { label: 'Notifications', to: '/assistant/notifications', icon: 'bell' },
    { label: 'Profile', to: '/assistant/profile', icon: 'user' },
];
</script>

<template>
    <PortalShell brand="Atlas Academy" subtitle="Student Operations" :nav="nav" role-label="Assistant">
        <slot />
    </PortalShell>
</template>
