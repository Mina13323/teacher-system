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

const { t } = useI18n();
const route = useRoute();
const toast = useToast();
const { fieldErrors } = useFieldErrors();
const authRole = route.path.startsWith('/assistant') ? 'assistant' : 'teacher';

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
    { value: 'all', label: 'جميع المواد / الشعب' },
    { value: 'history', label: 'التاريخ' },
    { value: 'geography', label: 'الجغرافيا' },
    { value: 'both', label: 'التاريخ والجغرافيا' },
    { value: 'general', label: 'عام' },
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
        toast.success('تم السماح بالدخول فوراً بنجاح');
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
    const text = `منصة المصري - بيانات الدخول الجديدة:\nكود الطالب: ${creds.student_code}\nاسم المستخدم: ${creds.login || creds.email}\nكلمة المرور: ${creds.temporary_password}`;
    navigator.clipboard.writeText(text);
    copied.value = true;
    toast.success(t('students.copied') || 'تم النسخ');
    setTimeout(() => { copied.value = false; }, 3000);
}

// PRINT CREDENTIALS
function openPrintSingle(s) {
    printStudents.value = [s];
}

function openPrintSelected() {
    if (!selectedIds.value.length) return;
    printStudents.value = items.value.filter((s) => selectedIds.value.includes(s.id));
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
    if (s.academic_subject === 'history') return 'التاريخ';
    if (s.academic_subject === 'geography') return 'الجغرافيا';
    if (s.academic_subject === 'both') return 'التاريخ والجغرافيا';
    return 'عام';
}

function getYearLabel(s) {
    if (s.academic_year_label) return s.academic_year_label;
    if (s.academic_year === 'secondary_1') return '1st Secondary (الصف الأول الثانوي)';
    if (s.academic_year === 'secondary_2') return '2nd Secondary (الصف الثاني الثانوي)';
    if (s.academic_year === 'secondary_3') return '3rd Secondary (الصف الثالث الثانوي)';
    return s.academic_year || 'غير محدد';
}

onMounted(() => load(1));
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between no-print">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('nav.students') || 'إدارة الطلاب' }}</h1>
                <p class="text-sm text-ink-500">إدارة حسابات الطلاب، الصف الدراسي، الشعبة، وحالات الاشتراك والدخول</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <AppButton v-if="selectedIds.length" variant="outline" size="sm" @click="openPrintSelected">
                    🖨️ طباعة كروت المحدد ({{ selectedIds.length }})
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
            <div v-else class="divide-y divide-ink-100">
                <div class="flex items-center gap-3 bg-ink-50 px-5 py-2.5 text-xs font-semibold text-ink-500 uppercase tracking-wider">
                    <input type="checkbox" class="h-4 w-4 rounded border-ink-300 text-terracotta-600" :checked="isAllSelected" @change="toggleSelectAll" />
                    <span class="w-24">كود الطالب</span>
                    <span class="flex-1">اسم الطالب والبيانات</span>
                    <span class="w-32 hidden md:inline">الصف والشعبة</span>
                    <span class="w-28">حالة الدخول</span>
                    <span class="w-auto text-left">الإجراءات</span>
                </div>

                <div v-for="s in filtered" :key="s.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <input type="checkbox" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 shrink-0" :checked="selectedIds.includes(s.id)" @change="toggleSelectStudent(s.id)" />

                    <div class="w-24 shrink-0 font-mono font-bold text-terracotta-700 text-sm">
                        {{ s.student_code || '---' }}
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-ink-900" dir="auto">{{ s.name }}</p>
                        </div>
                        <p class="text-xs text-ink-400 mt-0.5">{{ s.email }} {{ s.phone ? `· ${s.phone}` : '' }}</p>
                    </div>

                    <div class="w-32 shrink-0 hidden md:block text-xs text-ink-600 space-y-0.5">
                        <p class="font-medium text-ink-800">{{ getYearLabel(s) }}</p>
                        <p v-if="s.academic_year === 'secondary_3'" class="text-terracotta-700 font-semibold">{{ getSubjectLabel(s) }}</p>
                    </div>

                    <!-- Status Badges -->
                    <div class="w-28 shrink-0 flex items-center gap-1.5">
                        <AppBadge v-if="s.access_status === 'active' || (!s.access_status && s.is_active)" tone="success">
                            {{ $t('students.statusActive') || 'نشط' }}
                        </AppBadge>
                        <AppBadge v-else-if="s.access_status === 'due'" tone="warning">
                            {{ $t('students.statusDue') || 'مستحق التجديد' }}
                        </AppBadge>
                        <AppBadge v-else tone="danger">
                            {{ $t('students.statusSuspended') || 'معلق' }}
                        </AppBadge>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <router-link :to="`/${authRole}/students/${s.id}`">
                            <AppButton variant="outline" size="sm">{{ $t('common.view') }}</AppButton>
                        </router-link>
                        <router-link :to="`/${authRole}/students/${s.id}/edit`">
                            <AppButton variant="ghost" size="sm">{{ $t('common.edit') }}</AppButton>
                        </router-link>
                        <AppButton variant="outline" size="sm" class="text-emerald-700 border-emerald-300 hover:bg-emerald-50" @click="openAllowImmediately(s)">
                            ⚡ السماح فوراً
                        </AppButton>
                        <AppButton v-if="s.access_status === 'suspended' || !s.is_active" variant="outline" size="sm" class="text-blue-700 border-blue-300 hover:bg-blue-50" @click="openRestore(s)">
                            ♻️ استعادة
                        </AppButton>
                        <AppButton v-else variant="ghost" size="sm" class="text-amber-700 hover:bg-amber-50" @click="openSuspend(s)">
                            🚫 إيقاف
                        </AppButton>
                        <AppButton variant="ghost" size="sm" @click="openRenew(s)">
                            🔄 تجديد
                        </AppButton>
                        <AppButton variant="ghost" size="sm" @click="openResetCredentials(s)">
                            🔑 كلمة السر
                        </AppButton>
                        <AppButton variant="ghost" size="sm" @click="openPrintSingle(s)">
                            🖨️ طباعة
                        </AppButton>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3">
                <Pagination v-if="meta" :meta="meta" @change="load" />
            </div>
        </div>

        <!-- Suspend Modal -->
        <AppModal :open="Boolean(suspendTarget)" :title="'إيقاف حساب الطالب: ' + (suspendTarget?.name || '')" size="sm" @close="suspendTarget = null">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">سيتم تجميد وصول الطالب إلى المنصة مؤقتاً مع الحفاظ على كافة بياناته وسجلاته بالكامل.</p>
                <AppTextarea v-model="suspendReason" label="سبب الإيقاف (اختياري)" id="suspend-reason" :rows="2" placeholder="مثال: تأخر في السداد الشهري..." />
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="suspendBusy" @click="suspendTarget = null">إلغاء</AppButton>
                    <AppButton variant="danger" :loading="suspendBusy" @click="submitSuspend">تأكيد الإيقاف</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Restore Modal -->
        <AppModal :open="Boolean(restoreTarget)" :title="'استعادة الطالب: ' + (restoreTarget?.name || '')" size="sm" @close="restoreTarget = null">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">سيتم تفعيل حساب الطالب مجدداً وتحديد فترة وصول نشطة.</p>
                <AppInput v-model="restoreMonths" type="number" min="1" max="12" label="مدة تمديد الوصول (بالأشهر)" id="restore-months" />
                <AppTextarea v-model="restoreNotes" label="ملاحظات الاستعادة" id="restore-notes" :rows="2" />
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="restoreBusy" @click="restoreTarget = null">إلغاء</AppButton>
                    <AppButton :loading="restoreBusy" @click="submitRestore">تأكيد الاستعادة</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Allow Immediately Modal -->
        <AppModal :open="Boolean(allowTarget)" :title="'السماح بالدخول فوراً: ' + (allowTarget?.name || '')" size="sm" @close="allowTarget = null">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">سيتم إلغاء أي حالة إيقاف وتفعيل دخول الطالب فوراً مع إنشاء أو تمديد فترته النشطة.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <AppInput v-model="allowMonths" type="number" min="1" max="12" label="عدد الأشهر" id="allow-months" />
                    <AppInput v-model="allowAmount" type="number" min="0" step="0.5" label="المبلغ (اختياري)" id="allow-amount" placeholder="مثال: 200" />
                </div>
                <AppTextarea v-model="allowNotes" label="ملاحظات الموافقة" id="allow-notes" :rows="2" />
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="allowBusy" @click="allowTarget = null">إلغاء</AppButton>
                    <AppButton :loading="allowBusy" @click="submitAllowImmediately">تأكيد وتفعيل الدخول</AppButton>
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

                <AppTextarea v-model="renewNotes" :label="$t('students.renewalNotes')" id="renew-notes" :rows="2" placeholder="ملاحظات الدفع أو سبب الإيقاف..." />

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
                    هل أنت متأكد من إعادة توليد بيانات الدخول للطالب <strong>{{ resetTarget?.name }}</strong>؟
                </p>
                <p class="text-xs text-rose-600">
                    سيتم إنشاء كلمة مرور مؤقتة جديدة بالنموذج الرسمية (كود الطالب + 2026) وإنهاء أية جلسات نشطة.
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
                        <span class="text-xs font-medium text-ink-500 block">اسم المستخدم / البريد الإلكتروني</span>
                        <span class="text-sm font-mono text-ink-900 select-all">{{ revealCredentials.login || revealCredentials.email }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">كلمة المرور الرسمية الأولى</span>
                        <span class="text-base font-mono font-bold text-ink-900 bg-white border border-ink-200 px-3 py-1.5 rounded-lg inline-block select-all">{{ revealCredentials.temporary_password }}</span>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <AppButton variant="outline" @click="copyAllCredentials">
                        <span v-if="copied">✓ {{ $t('students.copied') }}</span>
                        <span v-else>📋 {{ $t('students.copyCredentials') }}</span>
                    </AppButton>
                    <AppButton @click="revealCredentials = null">{{ $t('common.confirm') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Print Credentials Modal / Sheet -->
        <AppModal :open="Boolean(printStudents.length)" title="طباعة بطاقة دخول الطالب" size="lg" @close="printStudents = []">
            <div class="space-y-6">
                <div class="no-print flex justify-between items-center border-b pb-3">
                    <p class="text-sm text-ink-600">اضغط على زر الطباعة لطباعة كارت الدخول للطالب بالهوية الرسمية لمنصة المصري.</p>
                    <AppButton @click="triggerPrint">🖨️ طباعة الآن</AppButton>
                </div>

                <!-- Printable Content Area -->
                <div class="printable-area space-y-8 p-4">
                    <div
                        v-for="st in printStudents"
                        :key="st.id"
                        class="print-card border-2 border-ink-900 rounded-xl p-6 bg-white max-w-md mx-auto space-y-4 shadow-sm page-break-after"
                    >
                        <div class="text-center border-b-2 border-ink-900 pb-3">
                            <h2 class="text-2xl font-black text-ink-900 tracking-wide">El Masry - المصري</h2>
                            <p class="text-sm font-bold text-ink-600 mt-1">بيانات دخول الطالب</p>
                        </div>

                        <div class="space-y-2 text-sm font-medium text-ink-900 dir-rtl">
                            <div class="flex justify-between border-b border-ink-100 py-1">
                                <span class="text-ink-500">اسم الطالب:</span>
                                <span class="font-bold text-ink-900">{{ st.name }}</span>
                            </div>
                            <div class="flex justify-between border-b border-ink-100 py-1">
                                <span class="text-ink-500">Student Code:</span>
                                <span class="font-mono font-bold text-terracotta-700">{{ st.student_code }}</span>
                            </div>
                            <div class="flex justify-between border-b border-ink-100 py-1">
                                <span class="text-ink-500">Email:</span>
                                <span class="font-mono">{{ st.email }}</span>
                            </div>
                            <div class="flex justify-between border-b border-ink-100 py-1">
                                <span class="text-ink-500">Password:</span>
                                <span class="font-mono font-bold text-ink-900">
                                    {{ st.student_code ? `${st.student_code}2026` : 'Password unavailable — Reset Credentials' }}
                                </span>
                            </div>
                            <div class="flex justify-between border-b border-ink-100 py-1">
                                <span class="text-ink-500">Academic Year:</span>
                                <span>{{ getYearLabel(st) }}</span>
                            </div>
                            <div v-if="st.academic_year === 'secondary_3'" class="flex justify-between border-b border-ink-100 py-1">
                                <span class="text-ink-500">Subject:</span>
                                <span class="font-bold text-terracotta-700">{{ getSubjectLabel(st) }}</span>
                            </div>
                            <div v-if="st.phone" class="flex justify-between py-1">
                                <span class="text-ink-500">Phone:</span>
                                <span>{{ st.phone }}</span>
                            </div>
                        </div>

                        <div class="text-center pt-3 border-t border-ink-200 text-xs text-ink-400">
                            https://maherelmasry.com
                        </div>
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
    </div>
</template>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .printable-area, .printable-area * {
        visibility: visible;
    }
    .printable-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    .no-print {
        display: none !important;
    }
    .page-break-after {
        page-break-after: always;
    }
}
</style>
