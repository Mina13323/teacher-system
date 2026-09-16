<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppModal from '@/components/ui/AppModal.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Icon from '@/components/ui/Icon.vue';
import WhatsAppContactModal from '@/components/students/WhatsAppContactModal.vue';

const { t } = useI18n();
const route = useRoute();
const toast = useToast();
const { fieldErrors } = useFieldErrors();
const authRole = route.path.startsWith('/assistant') ? 'assistant' : 'teacher';

// WhatsApp contact modal. Opened from the list with no credential payload, so
// the credentials template stays disabled there. The payload is only ever
// supplied straight from a live one-time reveal.
const whatsappStudent = ref(null);
const whatsappCredentials = ref(null);

function openWhatsAppFor(student) {
    // Always clear any previously revealed password so it can never leak into
    // a different student's message.
    whatsappCredentials.value = null;
    whatsappStudent.value = student;
}

// Reuses the password already generated and revealed by the create/reset flow.
// No request is made and no password is regenerated, so the value shown in the
// preview is exactly the value the student will log in with.
function openWhatsAppFromReveal() {
    const creds = revealCredentials.value;
    if (!creds) return;
    const student = items.value.find((s) => s.student_code === creds.student_code) || {};
    whatsappCredentials.value = creds;
    whatsappStudent.value = {
        ...student,
        student_code: creds.student_code,
        name: student.name || creds.student_code,
    };
    revealCredentials.value = null;
}

function closeWhatsApp() {
    whatsappStudent.value = null;
    whatsappCredentials.value = null;
}

// Explicit, staff-initiated reset so "Send Credentials" works even when no
// one-time reveal is in hand. Not silent: the teacher pressed a button labelled
// "Reset & send", and the modal shows the new password before anything is sent.
const resettingWhatsApp = ref(false);
async function whatsappResetAndSend() {
    const student = whatsappStudent.value;
    if (!student?.id) return;

    resettingWhatsApp.value = true;
    try {
        const res = await teacher.resetStudentCredentials(student.id);
        const data = res.data || res;
        // The fresh reveal flows straight into the open modal, which then
        // enables and selects the credentials template.
        whatsappCredentials.value = data.credentials;
        toast.success(t('students.passwordReset') || 'تم إعادة توليد كلمة المرور');
    } catch (e) {
        toast.error(e.message);
    } finally {
        resettingWhatsApp.value = false;
    }
}

const items = ref([]);
const meta = ref(null);
const page = ref(1);
const loading = ref(true);
const error = ref('');

// Selection for batch operations / printing
const selectedIds = ref([]);

// Filters
const search = ref('');
const yearFilter = ref('all');
const subjectFilter = ref('all');
const statusFilter = ref('all');

// Suspend modal state
const suspendTarget = ref(null);
const suspendReason = ref('');
const suspendBusy = ref(false);

// Restore modal state
const restoreTarget = ref(null);
const restoreMonths = ref(1);
const restoreNotes = ref('');
const restoreBusy = ref(false);

// Allow Immediately modal state
const allowTarget = ref(null);
const allowMonths = ref(1);
const allowAmount = ref('');
const allowNotes = ref('');
const allowBusy = ref(false);

// Renew modal state
const renewTarget = ref(null);
const renewDecision = ref('keep_active');
const renewMonths = ref(1);
const renewAmount = ref('');
const renewNotes = ref('');
const renewBusy = ref(false);

// Reset credentials modal state
const resetTarget = ref(null);
const revealCredentials = ref(null);
const resetBusy = ref(false);
const copied = ref(false);

// Print credentials state
const printStudents = ref([]);

// Notify modal state
const notifyTarget = ref(null);
const notifyForm = ref({ message: '' });
const notifyErrors = ref({});
const notifyBusy = ref(false);

const yearOptions = computed(() => [
    { value: 'all', label: t('students.filterAllYears') || 'جميع المراحل' },
    { value: 'secondary_1', label: t('students.secondary1') || 'الصف الأول الثانوي' },
    { value: 'secondary_2', label: t('students.secondary2') || 'الصف الثاني الثانوي' },
    { value: 'secondary_3', label: t('students.secondary3') || 'الصف الثالث الثانوي' },
]);

const subjectOptions = computed(() => [
    { value: 'all', label: t('subject.all') },
    { value: 'history', label: t('subject.history') },
    { value: 'geography', label: t('subject.geography') },
    { value: 'both', label: t('subject.both') },
    { value: 'general', label: t('subject.general') },
]);

const statusOptions = computed(() => [
    { value: 'all', label: t('status.all') || 'الكل' },
    { value: 'active', label: t('students.statusActive') || 'نشط' },
    { value: 'due', label: t('students.statusDue') || 'مستحق التجديد' },
    { value: 'suspended', label: t('students.statusSuspended') || 'معلق / موقوف' },
]);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return items.value.filter((s) => {
        const matchesQuery = !q ||
            (s.name || '').toLowerCase().includes(q) ||
            (s.email || '').toLowerCase().includes(q) ||
            (s.student_code || '').toLowerCase().includes(q) ||
            (s.phone || '').toLowerCase().includes(q);

        const matchesYear = yearFilter.value === 'all' || s.academic_year === yearFilter.value;
        const matchesSubject = subjectFilter.value === 'all' || (s.academic_subject || 'general') === subjectFilter.value;

        const currentStatus = s.access_status || (s.is_active ? 'active' : 'suspended');
        const matchesStatus = statusFilter.value === 'all' || currentStatus === statusFilter.value;

        return matchesQuery && matchesYear && matchesSubject && matchesStatus;
    });
});

const isAllSelected = computed(() => {
    if (!filtered.value.length) return false;
    return filtered.value.every((s) => selectedIds.value.includes(s.id));
});

function toggleSelectAll() {
    if (isAllSelected.value) {
        selectedIds.value = [];
    } else {
        selectedIds.value = filtered.value.map((s) => s.id);
    }
}

function toggleSelectStudent(id) {
    const idx = selectedIds.value.indexOf(id);
    if (idx > -1) {
        selectedIds.value.splice(idx, 1);
    } else {
        selectedIds.value.push(id);
    }
}

async function load(p = 1) {
    page.value = p;
    loading.value = true;
    error.value = '';
    try {
        const params = { per_page: 50, page: p };
        if (yearFilter.value !== 'all') params.academic_year = yearFilter.value;
        if (subjectFilter.value !== 'all') params.academic_subject = subjectFilter.value;
        if (search.value.trim()) params.search = search.value.trim();
        const res = toList(await teacher.students(params));
        items.value = res.items;
        meta.value = res.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

// SUSPEND
function openSuspend(s) {
    suspendTarget.value = s;
    suspendReason.value = '';
}

async function submitSuspend() {
    if (!suspendTarget.value) return;
    suspendBusy.value = true;
    try {
        const res = await teacher.suspendStudent(suspendTarget.value.id, { reason: suspendReason.value });
        toast.success(t('students.statusSuspended') || 'تم إيقاف الطالب بنجاح');
        suspendTarget.value = null;
        load(page.value);
    } catch (e) {
        toast.error(e.message);
    } finally {
        suspendBusy.value = false;
    }
}

// RESTORE
function openRestore(s) {
    restoreTarget.value = s;
    restoreMonths.value = 1;
    restoreNotes.value = '';
}

async function submitRestore() {
    if (!restoreTarget.value) return;
    restoreBusy.value = true;
    try {
        await teacher.restoreStudent(restoreTarget.value.id, {
            months: Number(restoreMonths.value),
            notes: restoreNotes.value,
        });
        toast.success(t('students.unsuspendSuccess') || 'تم استعادة الطالب بنجاح');
        restoreTarget.value = null;
        load(page.value);
    } catch (e) {
        toast.error(e.message);
    } finally {
        restoreBusy.value = false;
    }
}

// ALLOW IMMEDIATELY
function openAllowImmediately(s) {
    allowTarget.value = s;
    allowMonths.value = 1;
    allowAmount.value = '';
    allowNotes.value = '';
}

async function submitAllowImmediately() {
    if (!allowTarget.value) return;
    allowBusy.value = true;
    try {
        await teacher.allowStudentImmediately(allowTarget.value.id, {
            months: Number(allowMonths.value),
            amount: allowAmount.value ? Number(allowAmount.value) : null,
            notes: allowNotes.value,
        });
        toast.success(t('students.allowNowToast'));
        allowTarget.value = null;
        load(page.value);
    } catch (e) {
        toast.error(e.message);
    } finally {
        allowBusy.value = false;
    }
}

// RENEW ACCESS
function openRenew(s) {
    renewTarget.value = s;
    renewDecision.value = 'keep_active';
    renewMonths.value = 1;
    renewAmount.value = '';
    renewNotes.value = '';
}

async function submitRenew() {
    if (!renewTarget.value) return;
    renewBusy.value = true;
    try {
        const res = await teacher.renewStudent(renewTarget.value.id, {
            decision: renewDecision.value,
            months: renewDecision.value === 'keep_active' ? Number(renewMonths.value) : 1,
            amount: renewAmount.value ? Number(renewAmount.value) : null,
            notes: renewNotes.value || null,
        });

        const updated = res.data || res;
        renewTarget.value.access_status = updated.access_status;
        renewTarget.value.is_active = updated.is_active;

        toast.success(renewDecision.value === 'keep_active' ? t('students.statusActive') : t('students.statusSuspended'));
        renewTarget.value = null;
        load(page.value);
    } catch (e) {
        toast.error(e.message);
    } finally {
        renewBusy.value = false;
    }
}

// RESET CREDENTIALS
function openResetCredentials(s) {
    resetTarget.value = s;
}

async function submitResetCredentials() {
    if (!resetTarget.value) return;
    resetBusy.value = true;
    try {
        const res = await teacher.resetStudentCredentials(resetTarget.value.id);
        const data = res.data || res;
        revealCredentials.value = data.credentials;
        resetTarget.value = null;
        toast.success(t('students.passwordReset') || 'تم إعادة توليد كلمة المرور');
    } catch (e) {
        toast.error(e.message);
    } finally {
        resetBusy.value = false;
    }
}

function copyAllCredentials() {
    if (!revealCredentials.value) return;
    const creds = revealCredentials.value;
    const text = t('students.credentialsShareText', {
        code: creds.student_code,
        login: creds.login || creds.email,
        password: creds.temporary_password,
    });
    navigator.clipboard.writeText(text);
    copied.value = true;
    toast.success(t('students.copied') || 'تم النسخ');
    setTimeout(() => { copied.value = false; }, 3000);
}

// PRINT CREDENTIALS
// A password hash cannot be reversed, so a print sheet can only show a
// password that is currently held in memory from a one-time reveal. Batch
// printing has no such payload and therefore prints "unavailable" rather than
// reconstructing anything.
function openPrintSingle(s) {
    printStudents.value = [s];
}

function openPrintSelected() {
    if (!selectedIds.value.length) return;
    printStudents.value = items.value.filter((s) => selectedIds.value.includes(s.id));
}

// Print straight from the credentials reveal, carrying the revealed plaintext
// for this one render. The value lives only in this ref and is discarded when
// the print sheet closes.
function openPrintRevealed() {
    const creds = revealCredentials.value;
    if (!creds) return;
    const student = items.value.find((s) => s.student_code === creds.student_code) || {};
    printStudents.value = [{
        ...student,
        student_code: creds.student_code,
        email: creds.login || creds.email || student.email,
        revealed_password: creds.temporary_password,
    }];
    revealCredentials.value = null;
}

function triggerPrint() {
    window.print();
}

// NOTIFY
function openNotify(s) {
    notifyTarget.value = s;
    notifyForm.value = { message: '' };
    notifyErrors.value = {};
}
async function submitNotify() {
    notifyBusy.value = true;
    notifyErrors.value = {};
    try {
        await teacher.notifyStudent(notifyTarget.value.id, notifyForm.value);
        toast.success(t('students.messageSent') || 'تم إرسال الرسالة');
        notifyTarget.value = null;
    } catch (e) {
        notifyErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        notifyBusy.value = false;
    }
}

function getSubjectLabel(s) {
    if (s.academic_subject_label) return s.academic_subject_label;
    if (s.academic_subject === 'history') return t('subject.history');
    if (s.academic_subject === 'geography') return t('subject.geography');
    if (s.academic_subject === 'both') return t('subject.both');
    return t('subject.general');
}

function getYearLabel(s) {
    if (s.academic_year_label) return s.academic_year_label;
    if (s.academic_year === 'secondary_1') return t('students.secondary1');
    if (s.academic_year === 'secondary_2') return t('students.secondary2');
    if (s.academic_year === 'secondary_3') return t('students.secondary3');
    return s.academic_year || t('students.yearUnspecified');
}

onMounted(() => load(1));
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between no-print">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('nav.students') || 'إدارة الطلاب' }}</h1>
                <p class="text-sm text-ink-500">{{ $t('students.pageSubtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <AppButton v-if="selectedIds.length" variant="outline" size="sm" @click="openPrintSelected">
                    🖨️ {{ $t('students.printSelected') }} ({{ selectedIds.length }})
                </AppButton>
                <router-link :to="`/${authRole}/students/new`">
                    <AppButton>{{ $t('students.addStudent') || 'إضافة طالب جديد' }}</AppButton>
                </router-link>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between no-print">
            <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center flex-wrap">
                <div class="w-full max-w-xs">
                    <AppInput v-model="search" :placeholder="$t('common.search') + '...'" id="student-search" @input="load(1)" />
                </div>
                <div class="w-full max-w-[180px]">
                    <AppSelect v-model="yearFilter" :options="yearOptions" id="filter-year" @change="load(1)" />
                </div>
                <div class="w-full max-w-[180px]">
                    <AppSelect v-model="subjectFilter" :options="subjectOptions" id="filter-subject" @change="load(1)" />
                </div>
                <div class="w-full max-w-[160px]">
                    <AppSelect v-model="statusFilter" :options="statusOptions" id="filter-status" @change="load(1)" />
                </div>
            </div>
            <span class="text-sm text-ink-400">{{ $t('students.count', { n: meta?.total ?? filtered.length }) }}</span>
        </div>

        <!-- Student List Table -->
        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm no-print">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!filtered.length" icon="users" :title="$t('students.emptyTitle')" :message="$t('students.emptyMessage')">
                <router-link :to="`/${authRole}/students/new`"><AppButton>{{ $t('students.addStudent') }}</AppButton></router-link>
            </EmptyState>
            <div v-else class="overflow-x-auto">
                <table class="min-w-full divide-y divide-ink-100 text-start text-sm border-collapse">
                    <thead class="bg-ink-50 text-xs font-semibold text-ink-500 uppercase tracking-wider">
                        <tr>
                            <th scope="col" class="w-12 px-4 py-3 text-center align-middle">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400"
                                    :checked="isAllSelected"
                                    @change="toggleSelectAll"
                                />
                            </th>
                            <th scope="col" class="px-4 py-3 text-start whitespace-nowrap align-middle">
                                {{ $t('students.colCode') }}
                            </th>
                            <th scope="col" class="px-4 py-3 text-start min-w-[200px] align-middle">
                                {{ $t('students.colStudent') }}
                            </th>
                            <th scope="col" class="px-4 py-3 text-start whitespace-nowrap align-middle hidden md:table-cell">
                                {{ $t('students.colYearTrack') }}
                            </th>
                            <th scope="col" class="px-4 py-3 text-start whitespace-nowrap align-middle">
                                {{ $t('students.colAccess') }}
                            </th>
                            <th scope="col" class="px-4 py-3 text-end whitespace-nowrap align-middle">
                                {{ $t('students.colActions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100 bg-white">
                        <tr v-for="s in filtered" :key="s.id" class="hover:bg-ink-50/50 transition-colors">
                            <td class="w-12 px-4 py-3.5 text-center align-middle">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400"
                                    :checked="selectedIds.includes(s.id)"
                                    @change="toggleSelectStudent(s.id)"
                                />
                            </td>

                            <td class="px-4 py-3.5 whitespace-nowrap align-middle">
                                <span class="font-mono font-bold text-terracotta-700 text-sm">
                                    {{ s.student_code || '---' }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 align-middle min-w-[200px]">
                                <p class="font-semibold text-ink-900 whitespace-nowrap" dir="auto">
                                    {{ s.name }}
                                </p>
                                <div class="text-xs text-ink-400 mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <span>{{ s.email }}</span>
                                    <span v-if="s.phone" class="text-ink-300">·</span>
                                    <span v-if="s.phone" dir="ltr">{{ s.phone }}</span>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 whitespace-nowrap align-middle hidden md:table-cell text-xs text-ink-600">
                                <p class="font-medium text-ink-800">{{ getYearLabel(s) }}</p>
                                <p v-if="s.academic_year === 'secondary_3'" class="text-terracotta-700 font-semibold mt-0.5">
                                    {{ getSubjectLabel(s) }}
                                </p>
                            </td>

                            <td class="px-4 py-3.5 whitespace-nowrap align-middle">
                                <AppBadge v-if="s.access_status === 'active' || (!s.access_status && s.is_active)" tone="success">
                                    {{ $t('students.statusActive') || 'نشط' }}
                                </AppBadge>
                                <AppBadge v-else-if="s.access_status === 'due'" tone="warning">
                                    {{ $t('students.statusDue') || 'مستحق التجديد' }}
                                </AppBadge>
                                <AppBadge v-else tone="danger">
                                    {{ $t('students.statusSuspended') || 'معلق' }}
                                </AppBadge>
                            </td>

                            <td class="px-4 py-3.5 whitespace-nowrap text-end align-middle">
                                <div class="flex items-center justify-end gap-1.5 flex-nowrap">
                                    <router-link :to="`/${authRole}/students/${s.id}`">
                                        <AppButton variant="outline" size="sm" class="!px-2.5 !py-1 text-xs">
                                            {{ $t('common.view') }}
                                        </AppButton>
                                    </router-link>
                                    <router-link :to="`/${authRole}/students/${s.id}/edit`">
                                        <AppButton variant="ghost" size="sm" class="!px-2.5 !py-1 text-xs">
                                            {{ $t('common.edit') }}
                                        </AppButton>
                                    </router-link>
                                    <AppButton
                                        v-if="s.whatsapp_phone"
                                        variant="outline"
                                        size="sm"
                                        class="!px-2 !py-1 border-emerald-300 text-emerald-700 hover:bg-emerald-50"
                                        :title="$t('whatsapp.contactStudent')"
                                        :aria-label="$t('whatsapp.contactStudent')"
                                        @click="openWhatsAppFor(s)"
                                    >
                                        <Icon name="whatsapp" :size="15" />
                                    </AppButton>
                                    <AppButton
                                        variant="outline"
                                        size="sm"
                                        class="!px-2.5 !py-1 text-xs text-emerald-700 border-emerald-300 hover:bg-emerald-50"
                                        @click="openAllowImmediately(s)"
                                    >
                                        ⚡ {{ $t('students.actionAllowNow') }}
                                    </AppButton>
                                    <AppButton
                                        v-if="s.access_status === 'suspended' || !s.is_active"
                                        variant="outline"
                                        size="sm"
                                        class="!px-2.5 !py-1 text-xs text-blue-700 border-blue-300 hover:bg-blue-50"
                                        @click="openRestore(s)"
                                    >
                                        ♻️ {{ $t('students.actionRestore') }}
                                    </AppButton>
                                    <AppButton
                                        v-else
                                        variant="ghost"
                                        size="sm"
                                        class="!px-2.5 !py-1 text-xs text-amber-700 hover:bg-amber-50"
                                        @click="openSuspend(s)"
                                    >
                                        🚫 {{ $t('students.actionSuspend') }}
                                    </AppButton>
                                    <AppButton
                                        variant="ghost"
                                        size="sm"
                                        class="!px-2.5 !py-1 text-xs"
                                        @click="openRenew(s)"
                                    >
                                        🔄 {{ $t('students.actionRenew') }}
                                    </AppButton>
                                    <AppButton
                                        variant="ghost"
                                        size="sm"
                                        class="!px-2.5 !py-1 text-xs"
                                        @click="openResetCredentials(s)"
                                    >
                                        🔑 {{ $t('students.actionPassword') }}
                                    </AppButton>
                                    <AppButton
                                        variant="ghost"
                                        size="sm"
                                        class="!px-2.5 !py-1 text-xs"
                                        @click="openPrintSingle(s)"
                                    >
                                        🖨️ {{ $t('students.actionPrint') }}
                                    </AppButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-ink-100 px-4 py-3">
                <Pagination v-if="meta" :meta="meta" @change="load" />
            </div>
        </div>

        <!-- Suspend Modal -->
        <AppModal :open="Boolean(suspendTarget)" :title="$t('students.suspendModalTitle') + (suspendTarget?.name || '')" size="sm" @close="suspendTarget = null">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">{{ $t('students.suspendBody') }}</p>
                <AppTextarea v-model="suspendReason" :label="$t('students.suspendReasonLabel')" id="suspend-reason" :rows="2" :placeholder="$t('students.suspendReasonPlaceholder')" />
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="suspendBusy" @click="suspendTarget = null">{{ $t('common.cancel') }}</AppButton>
                    <AppButton variant="danger" :loading="suspendBusy" @click="submitSuspend">{{ $t('students.suspendConfirm') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Restore Modal -->
        <AppModal :open="Boolean(restoreTarget)" :title="$t('students.restoreModalTitle') + (restoreTarget?.name || '')" size="sm" @close="restoreTarget = null">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">{{ $t('students.restoreBody') }}</p>
                <AppInput v-model="restoreMonths" type="number" min="1" max="12" :label="$t('students.restoreMonthsLabel')" id="restore-months" />
                <AppTextarea v-model="restoreNotes" :label="$t('students.restoreNotesLabel')" id="restore-notes" :rows="2" />
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="restoreBusy" @click="restoreTarget = null">{{ $t('common.cancel') }}</AppButton>
                    <AppButton :loading="restoreBusy" @click="submitRestore">{{ $t('students.restoreConfirm') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Allow Immediately Modal -->
        <AppModal :open="Boolean(allowTarget)" :title="$t('students.allowModalTitle') + (allowTarget?.name || '')" size="sm" @close="allowTarget = null">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">{{ $t('students.allowBody') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <AppInput v-model="allowMonths" type="number" min="1" max="12" :label="$t('students.allowMonthsLabel')" id="allow-months" />
                    <AppInput v-model="allowAmount" type="number" min="0" step="0.5" :label="$t('students.allowAmountLabel')" id="allow-amount" :placeholder="$t('students.allowAmountPlaceholder')" />
                </div>
                <AppTextarea v-model="allowNotes" :label="$t('students.allowNotesLabel')" id="allow-notes" :rows="2" />
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="allowBusy" @click="allowTarget = null">{{ $t('common.cancel') }}</AppButton>
                    <AppButton :loading="allowBusy" @click="submitAllowImmediately">{{ $t('students.allowConfirm') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Renew Access Modal -->
        <AppModal :open="Boolean(renewTarget)" :title="$t('students.renewAccess') + ': ' + (renewTarget?.name || '')" size="sm" @close="renewTarget = null">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-ink-500 mb-2">{{ $t('students.renewalDecision') }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="rounded-lg border p-3 text-sm font-medium transition text-center"
                            :class="renewDecision === 'keep_active' ? 'border-emerald-600 bg-emerald-50 text-emerald-800 font-bold' : 'border-ink-200 hover:bg-ink-50 text-ink-700'"
                            @click="renewDecision = 'keep_active'"
                        >
                            ✓ {{ $t('students.keepActive') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border p-3 text-sm font-medium transition text-center"
                            :class="renewDecision === 'suspend' ? 'border-rose-600 bg-rose-50 text-rose-800 font-bold' : 'border-ink-200 hover:bg-ink-50 text-ink-700'"
                            @click="renewDecision = 'suspend'"
                        >
                            🚫 {{ $t('students.suspendAccess') }}
                        </button>
                    </div>
                </div>

                <div v-if="renewDecision === 'keep_active'" class="space-y-3">
                    <AppInput v-model="renewMonths" type="number" min="1" max="12" :label="$t('students.renewalMonths')" id="renew-months" />
                    <AppInput v-model="renewAmount" type="number" min="0" step="0.5" :label="$t('students.renewalAmount')" id="renew-amount" placeholder="e.g. 200" />
                </div>

                <AppTextarea v-model="renewNotes" :label="$t('students.renewalNotes')" id="renew-notes" :rows="2" :placeholder="$t('students.renewalNotesPlaceholder')" />

                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="renewBusy" @click="renewTarget = null">{{ $t('common.cancel') }}</AppButton>
                    <AppButton :loading="renewBusy" @click="submitRenew">{{ $t('common.saveChanges') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Confirm Reset Credentials Modal -->
        <AppModal :open="Boolean(resetTarget)" :title="$t('students.resetCredentials')" size="sm" @close="resetTarget = null">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">
                    {{ $t('students.regenConfirm') }} <strong>{{ resetTarget?.name }}</strong>?
                </p>
                <p class="text-xs text-rose-600">
                    {{ $t('students.regenBodyCredentials') }}
                </p>
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="resetBusy" @click="resetTarget = null">{{ $t('common.cancel') }}</AppButton>
                    <AppButton :loading="resetBusy" @click="submitResetCredentials">{{ $t('common.confirm') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Revealed Credentials Modal -->
        <AppModal :open="Boolean(revealCredentials)" :title="$t('students.credentialsModalTitle')" size="md" @close="revealCredentials = null">
            <div v-if="revealCredentials" class="space-y-4">
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-3.5 text-sm text-amber-900 flex items-start gap-2">
                    <span class="text-lg">⚠️</span>
                    <p class="font-medium">{{ $t('students.credentialsModalWarning') }}</p>
                </div>

                <div class="rounded-xl border border-ink-200 bg-ink-50/50 p-4 space-y-3">
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('students.studentCode') }}</span>
                        <span class="text-lg font-mono font-bold text-terracotta-700 select-all">{{ revealCredentials.student_code }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('students.usernameOrEmail') }}</span>
                        <span class="text-sm font-mono text-ink-900 select-all">{{ revealCredentials.login || revealCredentials.email }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('students.firstPasswordLabel') }}</span>
                        <span class="text-base font-mono font-bold text-ink-900 bg-white border border-ink-200 px-3 py-1.5 rounded-lg inline-block select-all">{{ revealCredentials.temporary_password }}</span>
                    </div>
                </div>

                <div class="flex flex-wrap justify-between items-center gap-2 pt-2">
                    <div class="flex flex-wrap gap-2">
                        <AppButton variant="outline" @click="copyAllCredentials">
                            <span v-if="copied">✓ {{ $t('students.copied') }}</span>
                            <span v-else>📋 {{ $t('students.copyCredentials') }}</span>
                        </AppButton>
                        <AppButton variant="outline" @click="openPrintRevealed">
                            🖨️ {{ $t('students.actionPrint') }}
                        </AppButton>
                        <AppButton
                            variant="outline"
                            class="border-emerald-300 text-emerald-700 hover:bg-emerald-50"
                            @click="openWhatsAppFromReveal"
                        >
                            <Icon name="whatsapp" :size="16" class="me-1 inline-block align-[-3px]" />
                            {{ $t('whatsapp.sendCredentials') }}
                        </AppButton>
                    </div>
                    <AppButton @click="revealCredentials = null">{{ $t('common.confirm') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Print Credentials Modal / Sheet -->
        <AppModal :open="Boolean(printStudents.length)" :title="$t('students.printModalTitle')" size="lg" @close="printStudents = []">
            <div class="space-y-6">
                <div class="no-print flex justify-between items-center border-b pb-3">
                    <p class="text-sm text-ink-600">{{ $t('students.printModalBody') }}</p>
                    <AppButton @click="triggerPrint">🖨️ {{ $t('students.printNow') }}</AppButton>
                </div>

                <!-- Printable Content Area -->
                <div class="printable-area">
                    <div v-for="st in printStudents" :key="st.id" class="print-card">
                        <div class="print-card__head">
                            <span class="print-card__brand">{{ $t('students.printBrand') }}</span>
                            <span class="print-card__code" dir="ltr">{{ st.student_code }}</span>
                        </div>

                        <div class="print-card__name" dir="rtl">{{ st.name }}</div>

                        <div class="print-card__grid">
                            <div class="print-card__row">
                                <span class="print-card__label">{{ $t('students.printCodeLabel') }}</span>
                                <span class="print-card__value print-card__value--mono" dir="ltr">{{ st.student_code }}</span>
                            </div>
                            <div class="print-card__row">
                                <span class="print-card__label">{{ $t('students.printEmailLabel') }}</span>
                                <span class="print-card__value print-card__value--mono" dir="ltr">{{ st.email }}</span>
                            </div>
                            <div class="print-card__row">
                                <span class="print-card__label">{{ $t('students.printPasswordLabel') }}</span>
                                <span class="print-card__value print-card__value--mono" dir="ltr">{{ st.revealed_password || $t('students.printPasswordUnavailable') }}</span>
                            </div>
                            <div class="print-card__row">
                                <span class="print-card__label">{{ $t('students.printYearLabel') }}</span>
                                <span class="print-card__value">{{ getYearLabel(st) }}</span>
                            </div>
                            <div v-if="st.academic_year === 'secondary_3'" class="print-card__row">
                                <span class="print-card__label">{{ $t('students.printSubjectLabel') }}</span>
                                <span class="print-card__value">{{ getSubjectLabel(st) }}</span>
                            </div>
                            <div v-if="st.phone" class="print-card__row">
                                <span class="print-card__label">{{ $t('students.printPhoneLabel') }}</span>
                                <span class="print-card__value" dir="ltr">{{ st.phone }}</span>
                            </div>
                        </div>

                        <div class="print-card__foot">https://maherelmasry.com</div>
                    </div>
                </div>
            </div>
        </AppModal>

        <!-- Notify Student Modal -->
        <AppModal :open="Boolean(notifyTarget)" :title="$t('students.messageStudent', { name: notifyTarget?.name })" size="sm" @close="notifyTarget = null">
            <AppTextarea v-model="notifyForm.message" :label="$t('students.message')" required id="notify-message" :error="notifyErrors.message" :rows="3" :placeholder="$t('students.messagePlaceholder')" />
            <template #footer>
                <AppButton variant="outline" :disabled="notifyBusy" @click="notifyTarget = null">{{ $t('common.cancel') }}</AppButton>
                <AppButton :loading="notifyBusy" @click="submitNotify">{{ $t('common.send') }}</AppButton>
            </template>
        </AppModal>

        <!-- WhatsApp contact -->
        <WhatsAppContactModal
            :open="Boolean(whatsappStudent)"
            :student="whatsappStudent"
            :credentials="whatsappCredentials"
            :resetting="resettingWhatsApp"
            @reset="whatsappResetAndSend"
            @close="closeWhatsApp"
        />
    </div>
</template>

<style>
/* Compact credential cards so several fit on one printed page. The styles apply
   on screen too, so the preview matches what actually prints. */
.printable-area { display: flex; flex-direction: column; gap: 12px; padding: 8px; }

.print-card {
    border: 1.5px solid #1b2635;
    border-radius: 8px;
    padding: 10px 12px;
    background: #fff;
    font-size: 11px;
    line-height: 1.35;
    break-inside: avoid;
    page-break-inside: avoid;
}
.print-card__head { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 1.5px solid #1b2635; padding-bottom: 4px; margin-bottom: 6px; }
.print-card__brand { font-weight: 800; font-size: 12px; letter-spacing: .02em; }
.print-card__code { font-family: monospace; font-weight: 700; color: #c1461f; }
.print-card__name { text-align: center; font-weight: 700; font-size: 12px; margin-bottom: 6px; }
.print-card__grid { display: flex; flex-direction: column; gap: 2px; }
.print-card__row { display: flex; justify-content: space-between; gap: 8px; }
.print-card__label { color: #5c7496; }
.print-card__value { font-weight: 600; text-align: right; }
.print-card__value--mono { font-family: monospace; }
.print-card__foot { text-align: center; margin-top: 6px; padding-top: 4px; border-top: 1px solid #c6cfdc; color: #7f93ad; font-size: 9px; }

@media print {
    body * { visibility: hidden; }
    .printable-area, .printable-area * { visibility: visible; }
    .printable-area { position: absolute; left: 0; top: 0; width: 100%; padding: 0; gap: 8px; }
    .no-print { display: none !important; }
    /* Let cards flow onto pages; only avoid splitting a single card mid-way. */
    .print-card { page-break-inside: avoid; }
}
</style>
