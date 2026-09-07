<script setup>
import PortalShell from './PortalShell.vue';

const nav = [
    { label: 'Dashboard', to: '/student', icon: 'home' },
    { label: 'My Courses', to: '/student/courses', icon: 'book' },
    { label: 'Exams', to: '/student/exams', icon: 'clipboard' },
    { label: 'Competitions', to: '/student/competitions', icon: 'trophy' },
    { label: 'Analytics', to: '/student/analytics', icon: 'chart' },
    { label: 'Notifications', to: '/student/notifications', icon: 'bell' },
    { label: 'Profile', to: '/student/profile', icon: 'user' },
];
</script>

<template>
    <PortalShell brand="Atlas Academy" subtitle="Student Portal" :nav="nav" role-label="Student">
        <slot />
    </PortalShell>
</template>
