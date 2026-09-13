<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/stores/auth';
import PortalShell from './PortalShell.vue';

const { t } = useI18n();
const auth = useAuthStore();

const nav = computed(() => {
    const items = [
        { label: t('nav.dashboard'), to: '/student', icon: 'home' },
    ];

    if (auth.canAccessLessons) {
        items.push({ label: t('dashboard.myCourses'), to: '/student/courses', icon: 'book' });
    }
    if (auth.canTakeExams) {
        items.push({ label: t('nav.exams'), to: '/student/exams', icon: 'clipboard' });
    }
    if (auth.canJoinCompetitions) {
        items.push({ label: t('nav.competitions'), to: '/student/competitions', icon: 'trophy' });
    }

    items.push(
        { label: t('nav.analytics'), to: '/student/analytics', icon: 'chart' },
        { label: t('nav.notifications'), to: '/student/notifications', icon: 'bell' },
        { label: t('nav.profile'), to: '/student/profile', icon: 'user' }
    );

    return items;
});
</script>

<template>
    <PortalShell :brand="$t('app.brand')" :subtitle="$t('app.studentPortal')" :nav="nav" :role-label="$t('app.roleStudent')">
        <div v-if="auth.isSuspended" class="mb-6 rounded-xl border border-rose-300 bg-rose-50 p-4 text-rose-800 shadow-sm flex items-start gap-3">
            <span class="text-xl">⚠️</span>
            <div>
                <p class="font-bold text-rose-900">{{ $t('students.statusSuspended') }}</p>
                <p class="text-sm text-rose-700 mt-0.5">{{ $t('students.accountSuspendedNotice') }}</p>
            </div>
        </div>
        <div v-else-if="auth.isRenewalDue" class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-800 shadow-sm flex items-start gap-3">
            <span class="text-xl">⏳</span>
            <div>
                <p class="font-bold text-amber-900">{{ $t('students.statusDue') }}</p>
                <p class="text-sm text-amber-700 mt-0.5">{{ $t('students.accountDueNotice') }}</p>
            </div>
        </div>
        <router-view />
    </PortalShell>
</template>
