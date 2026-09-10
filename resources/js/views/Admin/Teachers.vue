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

const { t } = useI18n();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const items = ref([]);
const meta = ref(null);
const loading = ref(true);
const error = ref('');

const resetTarget = ref(null);
const resetBusy = ref(false);
const resetForm = reactive({ password: '', password_confirmation: '' });
const resetErrors = ref({});

async function load(p = 1) {
    loading.value = true;
    try {
        const res = toList(await admin.teachers({ per_page: 10, page: p }));
        items.value = res.items;
        meta.value = res.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function setActive(tc, active) {
    try {
        const op = active ? admin.activateTeacher : admin.deactivateTeacher;
        await op(tc.id);
        tc.is_active = active;
        toast.success(active ? t('teachers.activated') : t('teachers.deactivated'));
    } catch (e) {
        toast.error(e.message);
    }
}

async function resetPassword() {
    resetBusy.value = true;
    resetErrors.value = {};
    try {
        await admin.resetTeacherPassword(resetTarget.value.id, {
            password: resetForm.password,
            password_confirmation: resetForm.password_confirmation,
        });
        toast.success(t('teachers.passwordReset'));
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
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('teachers.title') }}</h1>
                <p class="text-sm text-ink-500">{{ $t('teachers.subtitle') }}</p>
            </div>
            <router-link to="/admin/teachers/new"><AppButton>{{ $t('teachers.addTeacher') }}</AppButton></router-link>
        </div>

        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!items.length" icon="user" :title="$t('teachers.emptyTitle')" :message="$t('teachers.emptyMessage')">
                <router-link to="/admin/teachers/new"><AppButton>{{ $t('teachers.addTeacher') }}</AppButton></router-link>
            </EmptyState>
            <div v-else class="divide-y divide-ink-100">
                <div v-for="tc in items" :key="tc.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (tc.name || 'U').slice(0, 1) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-900" dir="auto">{{ tc.name }}</p>
                        <p class="text-sm text-ink-400">{{ tc.email }}</p>
                    </div>
                    <AppBadge :tone="tc.is_active ? 'success' : 'neutral'">{{ tc.is_active ? $t('status.active') : $t('status.inactive') }}</AppBadge>
                    <div class="flex items-center gap-2">
                        <router-link :to="`/admin/teachers/${tc.id}/edit`"><AppButton variant="outline" size="sm">{{ $t('common.edit') }}</AppButton></router-link>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100" @click="resetTarget = tc; resetForm.password = ''; resetForm.password_confirmation = ''">{{ $t('students.resetPassword') }}</button>
                        <AppButton v-if="tc.is_active" variant="outline" size="sm" @click="setActive(tc, false)">{{ $t('students.deactivate') }}</AppButton>
                        <AppButton v-else variant="success" size="sm" @click="setActive(tc, true)">{{ $t('students.activate') }}</AppButton>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="meta" :meta="meta" @change="load" /></div>
        </div>

        <AppModal :open="Boolean(resetTarget)" :title="$t('students.resetPassword')" size="sm" @close="resetTarget = null">
            <p class="mb-4 text-sm text-ink-600">{{ $t('students.newPasswordFor', { name: resetTarget?.name }) }}</p>
            <AppInput v-model="resetForm.password" :label="$t('common.newPassword')" type="password" id="admin-reset-password" required :error="resetErrors.password" autocomplete="new-password" :hint="$t('common.passwordMin')" />
            <AppInput v-model="resetForm.password_confirmation" :label="$t('common.confirmPassword')" type="password" id="admin-reset-confirm" required :error="resetErrors.password_confirmation" autocomplete="new-password" />
            <template #footer>
                <AppButton variant="outline" :disabled="resetBusy" @click="resetTarget = null">{{ $t('common.cancel') }}</AppButton>
                <AppButton :loading="resetBusy" @click="resetPassword">{{ $t('students.resetPassword') }}</AppButton>
            </template>
        </AppModal>
    </div>
</template>
