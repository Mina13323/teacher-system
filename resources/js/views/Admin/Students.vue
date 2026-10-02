<script setup>
import { ref, onMounted, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import { admin, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';

const { t } = useI18n();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const items = ref([]);
const meta = ref(null);
const loading = ref(true);
const error = ref('');

const search = ref('');
const statusFilter = ref('');
let searchDebounce = null;

const resetTarget = ref(null);
const resetBusy = ref(false);
const resetForm = reactive({ password: '', password_confirmation: '' });
const resetErrors = ref({});

async function load(p = 1) {
    loading.value = true;
    try {
        const params = { per_page: 15, page: p };
        if (search.value.trim()) params.search = search.value.trim();
        if (statusFilter.value) params.status = statusFilter.value;
        const res = toList(await admin.students(params));
        items.value = res.items;
        meta.value = res.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function onSearchInput() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => load(1), 300);
}

function clearFilters() {
    search.value = '';
    statusFilter.value = '';
    load(1);
}

async function setActive(s, active) {
    try {
        const op = active ? admin.activateStudent : admin.deactivateStudent;
        await op(s.id);
        s.is_active = active;
        toast.success(active ? t('students.activated') : t('students.deactivated'));
    } catch (e) {
        toast.error(e.message);
    }
}

async function resetPassword() {
    resetBusy.value = true;
    resetErrors.value = {};
    try {
        await admin.resetStudentPassword(resetTarget.value.id, {
            password: resetForm.password,
            password_confirmation: resetForm.password_confirmation,
        });
        toast.success(t('students.passwordReset'));
        resetTarget.value = null;
        resetForm.password = '';
        resetForm.password_confirmation = '';
    } catch (e) {
        resetErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? t('common.fixFields') : e.message);
    } finally {
        resetBusy.value = false;
    }
}

onMounted(() => load(1));
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('nav.students') }}</h1>
                <p class="text-sm text-ink-500">{{ $t('students.adminSubtitle') }}</p>
            </div>
            <div v-if="meta?.total !== undefined" class="text-xs font-medium text-ink-400">
                {{ meta.total }} {{ $t('nav.students') }}
            </div>
        </div>

        <!-- Search and Filter Bar -->
        <div class="flex flex-col gap-3 rounded-xl border border-ink-100 bg-white p-4 shadow-sm sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <AppInput
                    v-model="search"
                    :placeholder="$t('students.searchPlaceholder') || 'ابحث بالاسم أو كود الطالب أو البريد أو رقم الهاتف...'"
                    id="admin-student-search"
                    class="w-full"
                    @input="onSearchInput"
                />
                <button
                    v-if="search"
                    type="button"
                    class="absolute top-1/2 -translate-y-1/2 rounded-full p-1 text-ink-400 hover:bg-ink-100 hover:text-ink-600 ltr:right-3 rtl:left-3"
                    @click="search = ''; load(1)"
                    aria-label="مسح البحث"
                >
                    ✕
                </button>
            </div>
            <div class="w-full sm:w-44">
                <AppSelect
                    v-model="statusFilter"
                    :options="[
                        { value: '', label: $t('common.all') || 'جميع الحالات' },
                        { value: 'active', label: $t('status.active') },
                        { value: 'inactive', label: $t('status.inactive') },
                    ]"
                    id="admin-student-status"
                    @change="load(1)"
                />
            </div>
            <AppButton v-if="search || statusFilter" variant="ghost" size="sm" class="shrink-0 text-ink-500 hover:text-ink-700" @click="clearFilters">
                ✕ {{ $t('common.clear') || 'إلغاء' }}
            </AppButton>
        </div>

        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState
                v-else-if="!items.length"
                icon="users"
                :title="search || statusFilter ? ($t('students.notFound') || 'لا توجد نتائج مطابقة للبحث') : $t('students.adminEmptyTitle')"
                :message="search || statusFilter ? 'جرب البحث باسم أو كود أو بريد طالب آخر أو قم بإلغاء الفلتر' : $t('students.adminEmptyMessage')"
            >
                <AppButton v-if="search || statusFilter" variant="outline" size="sm" class="mt-2" @click="clearFilters">
                    إلغاء الفلترة والبحث
                </AppButton>
            </EmptyState>
            <div v-else class="divide-y divide-ink-100">
                <div v-for="s in items" :key="s.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (s.name || 'U').slice(0, 1) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-900" dir="auto">{{ s.name }}</p>
                        <p class="text-sm text-ink-400">{{ s.email }}</p>
                    </div>
                    <AppBadge :tone="s.is_active ? 'success' : 'neutral'">{{ s.is_active ? $t('status.active') : $t('status.inactive') }}</AppBadge>
                    <AppBadge :tone="s.profile_completed ? 'info' : 'warning'">{{ s.profile_completed ? $t('students.profileComplete') : $t('students.profileIncomplete') }}</AppBadge>
                    <div class="flex items-center gap-2">
                        <router-link :to="`/admin/students/${s.id}`"><AppButton variant="outline" size="sm">{{ $t('common.view') }}</AppButton></router-link>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100" @click="resetTarget = s; resetForm.password = ''; resetForm.password_confirmation = ''">{{ $t('students.resetPassword') }}</button>
                        <AppButton v-if="s.is_active" variant="outline" size="sm" @click="setActive(s, false)">{{ $t('students.deactivate') }}</AppButton>
                        <AppButton v-else variant="success" size="sm" @click="setActive(s, true)">{{ $t('students.activate') }}</AppButton>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="meta" :meta="meta" @change="load" /></div>
        </div>

        <AppModal :open="Boolean(resetTarget)" :title="$t('students.resetPassword')" size="sm" @close="resetTarget = null">
            <p class="mb-4 text-sm text-ink-600">{{ $t('students.newPasswordFor', { name: resetTarget?.name }) }}</p>
            <AppInput v-model="resetForm.password" :label="$t('common.newPassword')" type="password" id="admin-student-reset-password" required :error="resetErrors.password" autocomplete="new-password" :hint="$t('common.passwordMin')" />
            <AppInput v-model="resetForm.password_confirmation" :label="$t('common.confirmPassword')" type="password" id="admin-student-reset-confirm" required :error="resetErrors.password_confirmation" autocomplete="new-password" />
            <template #footer>
                <AppButton variant="outline" :disabled="resetBusy" @click="resetTarget = null">{{ $t('common.cancel') }}</AppButton>
                <AppButton :loading="resetBusy" @click="resetPassword">{{ $t('students.resetPassword') }}</AppButton>
            </template>
        </AppModal>
    </div>
</template>
