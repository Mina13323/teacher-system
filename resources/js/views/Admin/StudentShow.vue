<script setup>
import { ref, onMounted } from 'vue';
import { formatDate } from '@/utils/format';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { admin } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import StatCard from '@/components/ui/StatCard.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const toast = useToast();
const loading = ref(true);
const error = ref('');
const student = ref(null);
const analytics = ref(null);
const anonymizeOpen = ref(false);
const anonymizePhrase = ref('');
const anonymizeBusy = ref(false);
const forceDeleteOpen = ref(false);
const forceDeletePhrase = ref('');
const forceDeleteHistoryConfirmed = ref(false);
const forceDeleteBusy = ref(false);

async function load() {
    loading.value = true;
    try {
        const [s, a] = await Promise.all([
            admin.student(route.params.id),
            admin.studentAnalytics(route.params.id),
        ]);
        student.value = s;
        analytics.value = a;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function expectedAnonymizePhrase() {
    return `ANONYMIZE STUDENT ${student.value?.id}`;
}

function expectedForceDeletePhrase() {
    return `FORCE DELETE STUDENT ${student.value?.id}`;
}

async function anonymizeStudent() {
    if (!student.value || anonymizePhrase.value !== expectedAnonymizePhrase()) return;
    anonymizeBusy.value = true;
    try {
        await admin.anonymizeStudent(student.value.id, anonymizePhrase.value);
        toast.success(t('students.anonymizeSuccess'));
        anonymizeOpen.value = false;
        anonymizePhrase.value = '';
        await load();
    } catch (e) {
        toast.error(e.message);
    } finally {
        anonymizeBusy.value = false;
    }
}

async function forceDeleteStudent() {
    if (!student.value
        || forceDeletePhrase.value !== expectedForceDeletePhrase()
        || !forceDeleteHistoryConfirmed.value) return;

    forceDeleteBusy.value = true;
    try {
        await admin.forceDeleteStudent(student.value.id, {
            confirmation: forceDeletePhrase.value,
            delete_academic_history: true,
        });
        toast.success(t('students.forceDeleteSuccess'));
        await router.push('/admin/students');
    } catch (e) {
        toast.error(e.message);
    } finally {
        forceDeleteBusy.value = false;
    }
}

function openAnonymize() {
    anonymizePhrase.value = '';
    anonymizeOpen.value = true;
}

function openForceDelete() {
    forceDeletePhrase.value = '';
    forceDeleteHistoryConfirmed.value = false;
    forceDeleteOpen.value = true;
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <router-link to="/admin/students" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.students') }}</router-link>
                <h1 class="mt-2 text-2xl font-bold text-ink-900" dir="auto">{{ student?.name || $t('common.student') }}</h1>
                <p class="text-ink-500">{{ student?.email }}</p>
            </div>
            <div v-if="student" class="flex flex-wrap gap-2">
                <AppButton variant="outline" @click="openAnonymize">{{ $t('students.anonymizeStudent') }}</AppButton>
                <AppButton variant="danger" @click="openForceDelete">{{ $t('students.forceDeleteStudent') }}</AppButton>
            </div>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-4">
                <StatCard :label="$t('analytics.statusLabel')" :value="student.is_active ? $t('status.active') : $t('status.inactive')" icon="user" :tone="student.is_active ? 'emerald' : 'ink'" />
                <StatCard :label="$t('analytics.profileLabel')" :value="student.profile_completed ? $t('status.complete') : $t('status.incomplete')" icon="check" :tone="student.profile_completed ? 'success' : 'warning'" />
                <StatCard :label="$t('analytics.enrollments')" :value="analytics?.courses?.length ?? 0" icon="layers" tone="sky" />
                <StatCard :label="$t('analytics.avgScore')" :value="analytics?.average_score ?? '—'" icon="chart" tone="terracotta" />
            </div>

            <AppCard v-if="analytics?.courses?.length" :title="$t('analytics.enrollments')">
                <div class="space-y-2">
                    <div v-for="(e, idx) in analytics.courses" :key="idx" class="flex items-center gap-3 rounded-lg bg-ink-50 px-4 py-2.5 text-sm">
                        <span class="flex-1 font-medium text-ink-800" dir="auto">{{ e.title }}</span>
                        <span class="text-xs text-ink-400">{{ $t('common.enrolledAt', { date: formatDate(e.enrolled_at) }) }}</span>
                    </div>
                </div>
            </AppCard>

            <AppCard :title="$t('analytics.examHistory')">
                <EmptyState v-if="!analytics?.history?.length" icon="clipboard" :title="$t('analytics.noHistoryStudentTitle')" :message="$t('analytics.noHistoryStudentMessage')" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-ink-100 text-start text-xs uppercase tracking-wide text-ink-400"><th class="px-3 py-2">{{ $t('analytics.exam') }}</th><th class="px-3 py-2">{{ $t('analytics.score') }}</th><th class="px-3 py-2">{{ $t('analytics.result') }}</th><th class="px-3 py-2">{{ $t('analytics.date') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="h in analytics.history" :key="h.attempt_id" class="border-b border-ink-50">
                                <td class="px-3 py-2.5 font-medium text-ink-800" dir="auto">{{ h.exam_title }}</td>
                                <td class="px-3 py-2.5 text-ink-600">{{ h.percentage }}%</td>
                                <td class="px-3 py-2.5"><AppBadge :tone="h.passed === true ? 'success' : h.passed === false ? 'danger' : 'neutral'">{{ h.passed === true ? $t('status.passed') : h.passed === false ? $t('status.failed') : '—' }}</AppBadge></td>
                                <td class="px-3 py-2.5 text-ink-500">{{ formatDate(h.submitted_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard v-if="analytics?.competition_results?.length" :title="$t('analytics.competitionResults')">
                <div v-for="r in analytics.competition_results" :key="r.competition_id + r.rank" class="flex items-center gap-3 rounded-lg bg-ink-50 px-4 py-2.5 text-sm">
                    <span class="flex-1 font-medium text-ink-800" dir="auto">{{ r.competition_title }}</span>
                    <span class="text-ink-600">#{{ r.rank }}</span>
                    <AppBadge :tone="r.qualified ? 'success' : 'neutral'">{{ r.qualified ? $t('status.qualified') : '—' }}</AppBadge>
                </div>
            </AppCard>
        </template>

        <AppModal :open="anonymizeOpen" :title="$t('students.anonymizeStudent')" size="md" @close="anonymizeOpen = false">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">{{ $t('students.anonymizeDetails') }}</p>
                <AppInput
                    v-model="anonymizePhrase"
                    :label="$t('students.typeConfirmation')"
                    id="admin-student-anonymize-confirmation"
                    autocomplete="off"
                />
                <p class="rounded-lg bg-ink-50 p-3 font-mono text-xs text-ink-700">{{ expectedAnonymizePhrase() }}</p>
            </div>
            <template #footer>
                <AppButton variant="outline" :disabled="anonymizeBusy" @click="anonymizeOpen = false">{{ $t('common.cancel') }}</AppButton>
                <AppButton variant="warning" :disabled="anonymizePhrase !== expectedAnonymizePhrase()" :loading="anonymizeBusy" @click="anonymizeStudent">{{ $t('students.anonymizeStudent') }}</AppButton>
            </template>
        </AppModal>

        <AppModal :open="forceDeleteOpen" :title="$t('students.forceDeleteStudent')" size="md" :close-on-backdrop="false" @close="forceDeleteOpen = false">
            <div class="space-y-4">
                <p class="text-sm font-medium text-rose-700">{{ $t('students.forceDeleteDetails') }}</p>
                <label class="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                    <input v-model="forceDeleteHistoryConfirmed" type="checkbox" class="mt-0.5 rounded border-rose-300 text-rose-600 focus:ring-rose-500">
                    <span>{{ $t('students.confirmDeleteAcademicHistory') }}</span>
                </label>
                <AppInput
                    v-model="forceDeletePhrase"
                    :label="$t('students.typeConfirmation')"
                    id="admin-student-force-delete-confirmation"
                    autocomplete="off"
                />
                <p class="rounded-lg bg-rose-50 p-3 font-mono text-xs text-rose-800">{{ expectedForceDeletePhrase() }}</p>
            </div>
            <template #footer>
                <AppButton variant="outline" :disabled="forceDeleteBusy" @click="forceDeleteOpen = false">{{ $t('common.cancel') }}</AppButton>
                <AppButton variant="danger" :disabled="forceDeletePhrase !== expectedForceDeletePhrase() || !forceDeleteHistoryConfirmed" :loading="forceDeleteBusy" @click="forceDeleteStudent">{{ $t('students.forceDeleteStudent') }}</AppButton>
            </template>
        </AppModal>
    </div>
</template>
