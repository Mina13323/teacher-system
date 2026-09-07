<script setup>
import PortalShell from './PortalShell.vue';

const nav = [
    { label: 'Dashboard', to: '/teacher', icon: 'home' },
    { label: 'Students', to: '/teacher/students', icon: 'users' },
    { label: 'Assistants', to: '/teacher/assistants', icon: 'user' },
    { label: 'Courses', to: '/teacher/courses', icon: 'book' },
    { label: 'Competitions', to: '/teacher/competitions', icon: 'trophy' },
    { label: 'Analytics', to: '/teacher/analytics', icon: 'chart' },
    { label: 'Integrity', to: '/teacher/integrity', icon: 'shield' },
    { label: 'Notifications', to: '/teacher/notifications', icon: 'bell' },
    { label: 'Profile', to: '/teacher/profile', icon: 'user' },
];
</script>

<template>
    <PortalShell brand="Atlas Academy" subtitle="Teacher Portal" :nav="nav" role-label="Teacher">
        <slot />
    </PortalShell>
</template>
