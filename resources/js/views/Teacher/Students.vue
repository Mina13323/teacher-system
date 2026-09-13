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

// Filters
const search = ref('');
const yearFilter = ref('all');
const statusFilter = ref('all');

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

// Notify modal state
const notifyTarget = ref(null);
const notifyForm = ref({ message: '' });
const notifyErrors = ref({});
const notifyBusy = ref(false);

const yearOptions = computed(() => [
    { value: 'all', label: t('students.filterAllYears') },
    { value: 'secondary_1', label: t('students.secondary1') },
    { value: 'secondary_2', label: t('students.secondary2') },
    { value: 'secondary_3', label: t('students.secondary3') },
]);

const statusOptions = computed(() => [
    { value: 'all', label: t('status.all') || 'الكل' },
    { value: 'active', label: t('students.statusActive') },
    { value: 'due', label: t('students.statusDue') },
    { value: 'suspended', label: t('students.statusSuspended') },
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

        const currentStatus = s.access_status || (s.is_active ? 'active' : 'suspended');
        const matchesStatus = statusFilter.value === 'all' || currentStatus === statusFilter.value;

        return matchesQuery && matchesYear && matchesStatus;
    });
});

async function load(p = 1) {
    page.value = p;
    loading.value = true;
    error.value = '';
    try {
        const params = { per_page: 25, page: p };
        if (yearFilter.value !== 'all') params.academic_year = yearFilter.value;
        const res = toList(await teacher.students(params));
        items.value = res.items;
        meta.value = res.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function setActive(s, active) {
    try {
        const res = await (active ? teacher.activateStudent : teacher.deactivateStudent)(s.id);
        const data = res.data || res;
        s.is_active = data.is_active !== undefined ? data.is_active : active;
        s.access_status = data.access_status || (active ? 'active' : 'suspended');
        toast.success(active ? t('students.unsuspendSuccess') : t('students.deactivated'));
        load(page.value);
    } catch (e) {
        toast.error(e.message);
    }
}

// DELETE STUDENT
const deleteTarget = ref(null);
const deleteBusy = ref(false);

function openDelete(s) {
    deleteTarget.value = s;
}

async function submitDelete() {
    if (!deleteTarget.value) return;
    deleteBusy.value = true;
    try {
        await teacher.deleteStudent(deleteTarget.value.id);
        toast.success(t('students.deleted'));
        deleteTarget.value = null;
        load(page.value);
    } catch (e) {
        toast.error(e.message);
    } finally {
        deleteBusy.value = false;
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

// RESET CREDENTIALS (Deterministic + Secure Temp Password)
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
        toast.success(t('students.passwordReset'));
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
    toast.success(t('students.copied'));
    setTimeout(() => { copied.value = false; }, 3000);
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
        toast.success(t('students.messageSent'));
        notifyTarget.value = null;
    } catch (e) {
        notifyErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        notifyBusy.value = false;
    }
}

onMounted(() => load(1));
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('nav.students') }}</h1>
                <p class="text-sm text-ink-500">{{ $t('students.subtitle') }}</p>
            </div>
            <router-link :to="`/${authRole}/students/new`"><AppButton>{{ $t('students.addStudent') }}</AppButton></router-link>
        </div>

        <!-- Filter Controls -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                <div class="w-full max-w-xs">
                    <AppInput v-model="search" :placeholder="$t('common.search') + '...'" id="student-search" />
                </div>
                <div class="w-full max-w-[180px]">
                    <AppSelect v-model="yearFilter" :options="yearOptions" id="filter-year" />
                </div>
                <div class="w-full max-w-[160px]">
                    <AppSelect v-model="statusFilter" :options="statusOptions" id="filter-status" />
                </div>
            </div>
            <span class="text-sm text-ink-400">{{ $t('students.count', { n: meta?.total ?? filtered.length }) }}</span>
        </div>

        <!-- Student List Table / Card -->
        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!filtered.length" icon="users" :title="$t('students.emptyTitle')" :message="$t('students.emptyMessage')">
                <router-link :to="`/${authRole}/students/new`"><AppButton>{{ $t('students.addStudent') }}</AppButton></router-link>
            </EmptyState>
            <div v-else class="divide-y divide-ink-100">
                <div v-for="s in filtered" :key="s.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">
                        {{ (s.name || 'U').slice(0, 1) }}
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-ink-900" dir="auto">{{ s.name }}</p>
                            <span v-if="s.student_code" class="inline-flex items-center rounded bg-ink-100 px-2 py-0.5 text-xs font-mono font-bold text-ink-700">
                                {{ s.student_code }}
                            </span>
                            <span v-if="s.academic_year" class="inline-flex items-center rounded bg-terracotta-50 px-2 py-0.5 text-xs font-medium text-terracotta-700">
                                {{ $t(`students.${s.academic_year === 'secondary_1' ? 'secondary1' : s.academic_year === 'secondary_2' ? 'secondary2' : 'secondary3'}`) }}
                            </span>
                        </div>
                        <p class="text-xs text-ink-400 mt-0.5">{{ s.email }} {{ s.phone ? `· ${s.phone}` : '' }}</p>
                    </div>

                    <!-- Status Badges -->
                    <div class="flex items-center gap-2 shrink-0">
                        <AppBadge v-if="s.access_status === 'active' || (!s.access_status && s.is_active)" tone="success">
                            {{ $t('students.statusActive') }}
                        </AppBadge>
                        <AppBadge v-else-if="s.access_status === 'due'" tone="warning">
                            {{ $t('students.statusDue') }}
                        </AppBadge>
                        <AppBadge v-else tone="danger">
                            {{ $t('students.statusSuspended') }}
                        </AppBadge>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap items-center gap-2">
                        <router-link :to="`/${authRole}/students/${s.id}`">
                            <AppButton variant="outline" size="sm">{{ $t('common.view') }}</AppButton>
                        </router-link>
                        <router-link :to="`/${authRole}/students/${s.id}/edit`">
                            <AppButton variant="ghost" size="sm">{{ $t('common.edit') }}</AppButton>
                        </router-link>
                        <AppButton
                            v-if="s.access_status === 'suspended' || !s.is_active"
                            variant="outline"
                            size="sm"
                            class="text-emerald-700 border-emerald-300 hover:bg-emerald-50"
                            @click="setActive(s, true)"
                        >
                            ✅ {{ $t('students.unsuspend') }}
                        </AppButton>
                        <AppButton variant="outline" size="sm" @click="openRenew(s)">
                            🔄 {{ $t('students.renewAccess') }}
                        </AppButton>
                        <AppButton variant="ghost" size="sm" @click="openResetCredentials(s)">
                            🔑 {{ $t('students.resetCredentials') }}
                        </AppButton>
                        <AppButton variant="ghost" size="sm" @click="openNotify(s)">
                            💬 {{ $t('students.notify') }}
                        </AppButton>
                        <AppButton
                            variant="ghost"
                            size="sm"
                            class="text-rose-600 hover:text-rose-700 hover:bg-rose-50"
                            @click="openDelete(s)"
                        >
                            🗑️ {{ $t('common.delete') }}
                        </AppButton>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3">
                <Pagination v-if="meta" :meta="meta" @change="load" />
            </div>
        </div>

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
                    سيتم إنشاء كلمة مرور مؤقتة جديدة وإنهاء أي جلسات نشطة حالية للطالب فوراً.
                </p>
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="resetBusy" @click="resetTarget = null">{{ $t('common.cancel') }}</AppButton>
                    <AppButton :loading="resetBusy" @click="submitResetCredentials">{{ $t('common.confirm') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- One-Time Revealed Credentials Modal -->
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
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('auth.email') }} / اسم المستخدم</span>
                        <span class="text-sm font-mono text-ink-900 select-all">{{ revealCredentials.login || revealCredentials.email }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('auth.password') }} (كلمة المرور المؤقتة الجديدة)</span>
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

        <!-- Notify Student Modal -->
        <AppModal :open="Boolean(notifyTarget)" :title="$t('students.messageStudent', { name: notifyTarget?.name })" size="sm" @close="notifyTarget = null">
            <AppTextarea v-model="notifyForm.message" :label="$t('students.message')" required id="notify-message" :error="notifyErrors.message" :rows="3" :placeholder="$t('students.messagePlaceholder')" />
            <template #footer>
                <AppButton variant="outline" :disabled="notifyBusy" @click="notifyTarget = null">{{ $t('common.cancel') }}</AppButton>
                <AppButton :loading="notifyBusy" @click="submitNotify">{{ $t('common.send') }}</AppButton>
            </template>
        </AppModal>

        <!-- Delete Student Modal -->
        <AppModal :open="Boolean(deleteTarget)" :title="$t('students.deleteStudent')" size="sm" @close="deleteTarget = null">
            <div class="space-y-4">
                <p class="text-sm text-ink-700" dir="auto">
                    {{ $t('students.deleteStudentConfirm', { name: deleteTarget?.name || '' }) }}
                </p>
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="deleteBusy" @click="deleteTarget = null">{{ $t('common.cancel') }}</AppButton>
                    <AppButton variant="danger" :loading="deleteBusy" @click="submitDelete">{{ $t('common.delete') }}</AppButton>
                </div>
            </div>
        </AppModal>
    </div>
</template>
