<script setup>
import { ref, computed, onMounted } from 'vue';
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

function isTypingArabicText(text) {
    return /[\u0600-\u06FF]/.test(text || '');
}

async function copyPhrase(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(t('common.copied') || 'تم نسخ الكود بنجاح');
    } catch {
        toast.info(text);
    }
}

const isForceDeleteValid = computed(() => {
    return !!forceDeleteHistoryConfirmed.value
        && forceDeletePhrase.value.trim().toUpperCase() === expectedForceDeletePhrase();
});

const isAnonymizeValid = computed(() => {
    return anonymizePhrase.value.trim().toUpperCase() === expectedAnonymizePhrase();
});

async function anonymizeStudent() {
    if (!student.value || !isAnonymizeValid.value) return;
    anonymizeBusy.value = true;
    try {
        await admin.anonymizeStudent(student.value.id, expectedAnonymizePhrase());
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
    if (!student.value || !isForceDeleteValid.value) return;

    forceDeleteBusy.value = true;
    try {
        await admin.forceDeleteStudent(student.value.id, {
            confirmation: expectedForceDeletePhrase(),
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
                <p class="text-sm text-ink-700 leading-relaxed">{{ $t('students.anonymizeDetails') }}</p>

                <!-- Confirmation code display with copy and auto-fill -->
                <div class="rounded-xl border border-ink-200 bg-ink-50/80 p-3.5 space-y-2">
                    <div class="flex items-center justify-between text-xs font-medium text-ink-700">
                        <span>كود التأكيد المطلوب كتابته:</span>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md bg-white border border-ink-200 px-2.5 py-1 text-xs font-semibold text-ink-700 hover:bg-ink-100 transition-colors shadow-2xs"
                                @click="copyPhrase(expectedAnonymizePhrase())"
                            >
                                📋 نسخ الكود
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-amber-700 transition-colors shadow-2xs"
                                @click="anonymizePhrase = expectedAnonymizePhrase()"
                            >
                                ✍️ إدراج تلقائي
                            </button>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white px-3 py-2 font-mono text-sm font-bold text-ink-900 border border-ink-200 tracking-wider text-center select-all">
                        {{ expectedAnonymizePhrase() }}
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="block text-xs font-medium text-ink-700" for="admin-student-anonymize-confirmation">
                        أدخل كود التأكيد أعلاه (أو اضغط "إدراج تلقائي"):
                    </label>
                    <AppInput
                        v-model="anonymizePhrase"
                        :placeholder="expectedAnonymizePhrase()"
                        id="admin-student-anonymize-confirmation"
                        autocomplete="off"
                        class="font-mono text-sm"
                    />
                </div>

                <div v-if="isTypingArabicText(anonymizePhrase)" class="rounded-lg bg-amber-50 border border-amber-200 p-2.5 text-xs text-amber-800 flex items-center justify-between">
                    <span>⚠️ المطلوب إدخال الكود الإنجليزي أعلاه وليس النص العربي.</span>
                    <button type="button" class="font-bold underline text-amber-900 hover:text-amber-950" @click="anonymizePhrase = expectedAnonymizePhrase()">
                        اضغط هنا للإدراج
                    </button>
                </div>
            </div>
            <template #footer>
                <AppButton variant="outline" :disabled="anonymizeBusy" @click="anonymizeOpen = false">{{ $t('common.cancel') }}</AppButton>
                <AppButton
                    variant="warning"
                    :disabled="!isAnonymizeValid"
                    :loading="anonymizeBusy"
                    @click="anonymizeStudent"
                >
                    {{ $t('students.anonymizeStudent') }}
                </AppButton>
            </template>
        </AppModal>

        <AppModal :open="forceDeleteOpen" :title="$t('students.forceDeleteStudent')" size="md" :close-on-backdrop="false" @close="forceDeleteOpen = false">
            <div class="space-y-4">
                <p class="text-sm font-medium text-rose-700 leading-relaxed">{{ $t('students.forceDeleteDetails') }}</p>

                <!-- Checkbox for explicit academic history deletion -->
                <label class="flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50/70 p-3.5 text-sm text-rose-800 cursor-pointer">
                    <input v-model="forceDeleteHistoryConfirmed" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-rose-300 text-rose-600 focus:ring-rose-500">
                    <span class="font-medium leading-snug">{{ $t('students.confirmDeleteAcademicHistory') }}</span>
                </label>

                <!-- Confirmation code display with copy and auto-fill -->
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-3.5 space-y-2">
                    <div class="flex items-center justify-between text-xs font-medium text-rose-800">
                        <span>كود التأكيد المطلوب كتابته:</span>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md bg-white border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100 transition-colors shadow-2xs"
                                @click="copyPhrase(expectedForceDeletePhrase())"
                            >
                                📋 نسخ الكود
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md bg-rose-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-rose-700 transition-colors shadow-2xs"
                                @click="forceDeletePhrase = expectedForceDeletePhrase()"
                            >
                                ✍️ إدراج تلقائي
                            </button>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white px-3 py-2 font-mono text-sm font-bold text-rose-900 border border-rose-200 tracking-wider text-center select-all">
                        {{ expectedForceDeletePhrase() }}
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="block text-xs font-medium text-ink-700" for="admin-student-force-delete-confirmation">
                        أدخل كود التأكيد أعلاه (أو اضغط "إدراج تلقائي"):
                    </label>
                    <AppInput
                        v-model="forceDeletePhrase"
                        :placeholder="expectedForceDeletePhrase()"
                        id="admin-student-force-delete-confirmation"
                        autocomplete="off"
                        class="font-mono text-sm"
                    />
                </div>

                <!-- Helpful guidance if user typed Arabic text instead of code -->
                <div v-if="isTypingArabicText(forceDeletePhrase)" class="rounded-lg bg-amber-50 border border-amber-200 p-2.5 text-xs text-amber-800 flex items-center justify-between">
                    <span>⚠️ المطلوب إدخال الكود الإنجليزي أعلاه وليس النص العربي.</span>
                    <button type="button" class="font-bold underline text-amber-900 hover:text-amber-950" @click="forceDeletePhrase = expectedForceDeletePhrase()">
                        اضغط هنا للإدراج
                    </button>
                </div>
            </div>
            <template #footer>
                <AppButton variant="outline" :disabled="forceDeleteBusy" @click="forceDeleteOpen = false">{{ $t('common.cancel') }}</AppButton>
                <AppButton
                    variant="danger"
                    :disabled="!isForceDeleteValid"
                    :loading="forceDeleteBusy"
                    @click="forceDeleteStudent"
                >
                    {{ $t('students.forceDeleteStudent') }}
                </AppButton>
            </template>
        </AppModal>
    </div>
</template>
