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
        const res = toList(await admin.students({ per_page: 10, page: p }));
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
        const op = active ? admin.activateStudent : admin.deactivateStudent;
        await op(s.id);
        s.is_active = active;
        toast.success(active ? 'Student activated.' : 'Student deactivated.');
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
        toast.success('Student password reset.');
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
        <div>
            <h1 class="text-2xl font-bold text-ink-900">Students</h1>
            <p class="text-sm text-ink-500">Oversight of all student accounts.</p>
        </div>

        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!items.length" icon="users" title="No students yet" message="No student accounts exist." />
            <div v-else class="divide-y divide-ink-100">
                <div v-for="s in items" :key="s.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (s.name || 'U').slice(0, 1) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-900">{{ s.name }}</p>
                        <p class="text-sm text-ink-400">{{ s.email }}</p>
                    </div>
                    <AppBadge :tone="s.is_active ? 'success' : 'neutral'">{{ s.is_active ? 'Active' : 'Inactive' }}</AppBadge>
                    <AppBadge :tone="s.profile_completed ? 'info' : 'warning'">{{ s.profile_completed ? 'Profile complete' : 'Profile incomplete' }}</AppBadge>
                    <div class="flex items-center gap-2">
                        <router-link :to="`/admin/students/${s.id}`"><AppButton variant="outline" size="sm">View</AppButton></router-link>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100" @click="resetTarget = s; resetForm.password = ''; resetForm.password_confirmation = ''">Reset password</button>
                        <AppButton v-if="s.is_active" variant="outline" size="sm" @click="setActive(s, false)">Deactivate</AppButton>
                        <AppButton v-else variant="success" size="sm" @click="setActive(s, true)">Activate</AppButton>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="meta" :meta="meta" @change="load" /></div>
        </div>

        <AppModal :open="Boolean(resetTarget)" title="Reset password" size="sm" @close="resetTarget = null">
            <p class="mb-4 text-sm text-ink-600">Set a new password for {{ resetTarget?.name }}.</p>
            <AppInput v-model="resetForm.password" label="New password" type="password" id="admin-student-reset-password" required :error="resetErrors.password" autocomplete="new-password" hint="At least 8 characters." />
            <AppInput v-model="resetForm.password_confirmation" label="Confirm password" type="password" id="admin-student-reset-confirm" required :error="resetErrors.password_confirmation" autocomplete="new-password" />
            <template #footer>
                <AppButton variant="outline" :disabled="resetBusy" @click="resetTarget = null">Cancel</AppButton>
                <AppButton :loading="resetBusy" @click="resetPassword">Reset password</AppButton>
            </template>
        </AppModal>
    </div>
</template>
