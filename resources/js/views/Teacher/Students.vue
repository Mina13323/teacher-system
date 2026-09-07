<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppModal from '@/components/ui/AppModal.vue';
import Pagination from '@/components/ui/Pagination.vue';

const route = useRoute();
const toast = useToast();
const { fieldErrors } = useFieldErrors();
const authRole = route.path.startsWith('/assistant') ? 'assistant' : 'teacher';

const items = ref([]);
const meta = ref(null);
const page = ref(1);
const loading = ref(true);
const error = ref('');
const search = ref('');

const resetTarget = ref(null);
const resetForm = ref({ password: '', password_confirmation: '' });
const resetErrors = ref({});
const resetBusy = ref(false);

const notifyTarget = ref(null);
const notifyForm = ref({ message: '' });
const notifyErrors = ref({});
const notifyBusy = ref(false);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return items.value;
    return items.value.filter((s) =>
        (s.name || '').toLowerCase().includes(q) || (s.email || '').toLowerCase().includes(q),
    );
});

async function load(p = 1) {
    page.value = p;
    loading.value = true;
    error.value = '';
    try {
        const res = toList(await teacher.students({ per_page: 15, page: p }));
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
        await (active ? teacher.activateStudent : teacher.deactivateStudent)(s.id);
        s.is_active = active;
        toast.success(active ? 'Student activated.' : 'Student deactivated.');
    } catch (e) {
        toast.error(e.message);
    }
}

function openReset(s) {
    resetTarget.value = s;
    resetForm.value = { password: '', password_confirmation: '' };
    resetErrors.value = {};
}
async function submitReset() {
    resetBusy.value = true;
    resetErrors.value = {};
    try {
        await teacher.resetStudentPassword(resetTarget.value.id, resetForm.value);
        toast.success('Password reset.');
        resetTarget.value = null;
    } catch (e) {
        resetErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        resetBusy.value = false;
    }
}

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
        toast.success('Message sent.');
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
                <h1 class="text-2xl font-bold text-ink-900">Students</h1>
                <p class="text-sm text-ink-500">Manage the students under your course.</p>
            </div>
            <router-link :to="`/${authRole}/students/new`"><AppButton>Add student</AppButton></router-link>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="w-full max-w-xs">
                <AppInput v-model="search" placeholder="Filter current page…" id="student-search" label="Search" />
            </div>
            <span class="text-sm text-ink-400">{{ meta?.total ?? 0 }} students</span>
        </div>

        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!filtered.length" icon="users" title="No students found" message="Add a student or adjust your filter.">
                <router-link :to="`/${authRole}/students/new`"><AppButton>Add student</AppButton></router-link>
            </EmptyState>
            <div v-else class="divide-y divide-ink-100">
                <div v-for="s in filtered" :key="s.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (s.name || 'U').slice(0, 1) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-900">{{ s.name }}</p>
                        <p class="text-sm text-ink-400">{{ s.email }}</p>
                    </div>
                    <AppBadge :tone="s.is_active ? 'success' : 'neutral'">{{ s.is_active ? 'Active' : 'Inactive' }}</AppBadge>
                    <div class="flex flex-wrap items-center gap-2">
                        <router-link :to="`/${authRole}/students/${s.id}`"><AppButton variant="outline" size="sm">View</AppButton></router-link>
                        <router-link :to="`/${authRole}/students/${s.id}/edit`"><AppButton variant="ghost" size="sm">Edit</AppButton></router-link>
                        <AppButton variant="outline" size="sm" @click="openReset(s)">Reset password</AppButton>
                        <AppButton variant="ghost" size="sm" @click="openNotify(s)">Notify</AppButton>
                        <AppButton v-if="s.is_active" variant="outline" size="sm" @click="setActive(s, false)">Deactivate</AppButton>
                        <AppButton v-else variant="success" size="sm" @click="setActive(s, true)">Activate</AppButton>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3">
                <Pagination v-if="meta" :meta="meta" @change="load" />
            </div>
        </div>

        <AppModal :open="Boolean(resetTarget)" :title="`Reset ${resetTarget?.name}'s password`" size="sm" @close="resetTarget = null">
            <AppInput v-model="resetForm.password" label="New password" type="password" required id="reset-password" :error="resetErrors.password" autocomplete="new-password" hint="At least 8 characters." />
            <AppInput v-model="resetForm.password_confirmation" label="Confirm password" type="password" required id="reset-password-confirm" :error="resetErrors.password_confirmation" autocomplete="new-password" />
            <template #footer>
                <AppButton variant="outline" :disabled="resetBusy" @click="resetTarget = null">Cancel</AppButton>
                <AppButton :loading="resetBusy" @click="submitReset">Reset</AppButton>
            </template>
        </AppModal>

        <AppModal :open="Boolean(notifyTarget)" :title="`Message ${notifyTarget?.name}`" size="sm" @close="notifyTarget = null">
            <AppTextarea v-model="notifyForm.message" label="Message" required id="notify-message" :error="notifyErrors.message" :rows="3" placeholder="Write a short message…" />
            <template #footer>
                <AppButton variant="outline" :disabled="notifyBusy" @click="notifyTarget = null">Cancel</AppButton>
                <AppButton :loading="notifyBusy" @click="submitNotify">Send</AppButton>
            </template>
        </AppModal>
    </div>
</template>
