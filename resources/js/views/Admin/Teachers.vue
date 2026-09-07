<script setup>
import { ref, onMounted, reactive } from 'vue';
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

async function setActive(t, active) {
    try {
        const op = active ? admin.activateTeacher : admin.deactivateTeacher;
        await op(t.id);
        t.is_active = active;
        toast.success(active ? 'Teacher activated.' : 'Teacher deactivated.');
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
        toast.success('Teacher password reset.');
        resetTarget.value = null;
        resetForm.password = '';
        resetForm.password_confirmation = '';
    } catch (e) {
        resetErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? 'Please fix the highlighted fields.' : e.message);
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
                <h1 class="text-2xl font-bold text-ink-900">Teachers</h1>
                <p class="text-sm text-ink-500">Manage teacher accounts across the platform.</p>
            </div>
            <router-link to="/admin/teachers/new"><AppButton>Add teacher</AppButton></router-link>
        </div>

        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!items.length" icon="user" title="No teachers yet" message="Create a teacher account to get started.">
                <router-link to="/admin/teachers/new"><AppButton>Add teacher</AppButton></router-link>
            </EmptyState>
            <div v-else class="divide-y divide-ink-100">
                <div v-for="t in items" :key="t.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (t.name || 'U').slice(0, 1) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-900">{{ t.name }}</p>
                        <p class="text-sm text-ink-400">{{ t.email }}</p>
                    </div>
                    <AppBadge :tone="t.is_active ? 'success' : 'neutral'">{{ t.is_active ? 'Active' : 'Inactive' }}</AppBadge>
                    <div class="flex items-center gap-2">
                        <router-link :to="`/admin/teachers/${t.id}/edit`"><AppButton variant="outline" size="sm">Edit</AppButton></router-link>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100" @click="resetTarget = t; resetForm.password = ''; resetForm.password_confirmation = ''">Reset password</button>
                        <AppButton v-if="t.is_active" variant="outline" size="sm" @click="setActive(t, false)">Deactivate</AppButton>
                        <AppButton v-else variant="success" size="sm" @click="setActive(t, true)">Activate</AppButton>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="meta" :meta="meta" @change="load" /></div>
        </div>

        <AppModal :open="Boolean(resetTarget)" title="Reset password" size="sm" @close="resetTarget = null">
            <p class="mb-4 text-sm text-ink-600">Set a new password for {{ resetTarget?.name }}.</p>
            <AppInput v-model="resetForm.password" label="New password" type="password" id="admin-reset-password" required :error="resetErrors.password" autocomplete="new-password" hint="At least 8 characters." />
            <AppInput v-model="resetForm.password_confirmation" label="Confirm password" type="password" id="admin-reset-confirm" required :error="resetErrors.password_confirmation" autocomplete="new-password" />
            <template #footer>
                <AppButton variant="outline" :disabled="resetBusy" @click="resetTarget = null">Cancel</AppButton>
                <AppButton :loading="resetBusy" @click="resetPassword">Reset password</AppButton>
            </template>
        </AppModal>
    </div>
</template>
