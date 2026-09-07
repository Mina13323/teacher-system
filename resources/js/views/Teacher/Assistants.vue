<script setup>
import { ref, onMounted } from 'vue';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import Pagination from '@/components/ui/Pagination.vue';

const toast = useToast();
const { fieldErrors } = useFieldErrors();

const items = ref([]);
const meta = ref(null);
const loading = ref(true);
const error = ref('');

const resetTarget = ref(null);
const resetForm = ref({ password: '', password_confirmation: '' });
const resetErrors = ref({});
const resetBusy = ref(false);

async function load(p = 1) {
    loading.value = true;
    error.value = '';
    try {
        const res = toList(await teacher.assistants({ per_page: 15, page: p }));
        items.value = res.items;
        meta.value = res.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function setActive(a, active) {
    try {
        await (active ? teacher.activateAssistant : teacher.deactivateAssistant)(a.id);
        a.is_active = active;
        toast.success(active ? 'Assistant activated.' : 'Assistant deactivated.');
    } catch (e) {
        toast.error(e.message);
    }
}

function openReset(a) {
    resetTarget.value = a;
    resetForm.value = { password: '', password_confirmation: '' };
    resetErrors.value = {};
}
async function submitReset() {
    resetBusy.value = true;
    resetErrors.value = {};
    try {
        await teacher.resetAssistantPassword(resetTarget.value.id, resetForm.value);
        toast.success('Password reset.');
        resetTarget.value = null;
    } catch (e) {
        resetErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        resetBusy.value = false;
    }
}

onMounted(() => load(1));
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">Assistants</h1>
                <p class="text-sm text-ink-500">Operational staff who manage student accounts for you.</p>
            </div>
            <router-link to="/teacher/assistants/new"><AppButton>Add assistant</AppButton></router-link>
        </div>

        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!items.length" icon="user" title="No assistants yet" message="Assistants help manage students; create one to begin.">
                <router-link to="/teacher/assistants/new"><AppButton>Add assistant</AppButton></router-link>
            </EmptyState>
            <div v-else class="divide-y divide-ink-100">
                <div v-for="a in items" :key="a.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (a.name || 'U').slice(0, 1) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-900">{{ a.name }}</p>
                        <p class="text-sm text-ink-400">{{ a.email }}</p>
                    </div>
                    <AppBadge :tone="a.is_active ? 'success' : 'neutral'">{{ a.is_active ? 'Active' : 'Inactive' }}</AppBadge>
                    <div class="flex flex-wrap items-center gap-2">
                        <router-link :to="`/teacher/assistants/${a.id}/edit`"><AppButton variant="outline" size="sm">Edit</AppButton></router-link>
                        <AppButton variant="outline" size="sm" @click="openReset(a)">Reset password</AppButton>
                        <AppButton v-if="a.is_active" variant="outline" size="sm" @click="setActive(a, false)">Deactivate</AppButton>
                        <AppButton v-else variant="success" size="sm" @click="setActive(a, true)">Activate</AppButton>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="meta" :meta="meta" @change="load" /></div>
        </div>

        <AppModal :open="Boolean(resetTarget)" :title="`Reset ${resetTarget?.name}'s password`" size="sm" @close="resetTarget = null">
            <AppInput v-model="resetForm.password" label="New password" type="password" required id="as-reset-password" :error="resetErrors.password" autocomplete="new-password" hint="At least 8 characters." />
            <AppInput v-model="resetForm.password_confirmation" label="Confirm password" type="password" required id="as-reset-confirm" :error="resetErrors.password_confirmation" autocomplete="new-password" />
            <template #footer>
                <AppButton variant="outline" :disabled="resetBusy" @click="resetTarget = null">Cancel</AppButton>
                <AppButton :loading="resetBusy" @click="submitReset">Reset</AppButton>
            </template>
        </AppModal>
    </div>
</template>
