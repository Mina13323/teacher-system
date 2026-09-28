<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { publicStudentRegistration } from '@/api';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';

const route = useRoute();
const token = computed(() => String(route.params.token || ''));
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const credentials = ref(null);
const form = reactive({ name: '', phone: '', academic_year: 'secondary_1' });
const years = [
    { value: 'secondary_1', label: 'الصف الأول الثانوي' },
    { value: 'secondary_2', label: 'الصف الثاني الثانوي' },
    { value: 'secondary_3', label: 'الصف الثالث الثانوي' },
];

async function load() {
    try {
        await publicStudentRegistration.details(token.value);
    } catch (e) {
        error.value = e.message || 'This registration link is unavailable.';
    } finally {
        loading.value = false;
    }
}

async function submit() {
    saving.value = true;
    error.value = '';
    try {
        const res = await publicStudentRegistration.register(token.value, form);
        credentials.value = res.data || res;
    } catch (e) {
        error.value = e.message || 'Could not complete registration.';
    } finally {
        saving.value = false;
    }
}

function copyCredentials() {
    if (!credentials.value) return;
    navigator.clipboard.writeText(`Student code: ${credentials.value.student_code}\nUsername: ${credentials.value.login}\nTemporary password: ${credentials.value.temporary_password}`);
}

onMounted(load);
</script>

<template>
    <main class="min-h-screen bg-parchment-50 px-4 py-10">
        <div class="mx-auto max-w-lg">
            <AppCard>
                <template v-if="loading"><p class="text-ink-600">Loading registration…</p></template>
                <template v-else-if="error && !credentials"><p class="text-rose-700">{{ error }}</p></template>
                <template v-else-if="credentials">
                    <h1 class="text-2xl font-bold text-ink-900">Registration complete</h1>
                    <p class="mt-2 text-sm text-ink-600">Save these login credentials now. The temporary password is shown only once.</p>
                    <dl class="mt-6 space-y-3 rounded-xl bg-ink-50 p-4 text-sm">
                        <div><dt class="text-ink-500">Student code</dt><dd class="font-bold">{{ credentials.student_code }}</dd></div>
                        <div><dt class="text-ink-500">Username</dt><dd class="font-bold">{{ credentials.login }}</dd></div>
                        <div><dt class="text-ink-500">Temporary password</dt><dd class="font-bold">{{ credentials.temporary_password }}</dd></div>
                    </dl>
                    <AppButton class="mt-5" @click="copyCredentials">Copy credentials</AppButton>
                </template>
                <template v-else>
                    <h1 class="text-2xl font-bold text-ink-900">Student registration</h1>
                    <p class="mt-2 text-sm text-ink-600">Enter your details and we will create your login credentials.</p>
                    <form class="mt-6 space-y-4" @submit.prevent="submit">
                        <AppInput v-model="form.name" label="Student full name" required />
                        <AppInput v-model="form.phone" label="Phone number" placeholder="+201012345678" required />
                        <AppSelect v-model="form.academic_year" label="Academic year" :options="years" required />
                        <p v-if="error" class="text-sm text-rose-700">{{ error }}</p>
                        <AppButton type="submit" :loading="saving">Create my account</AppButton>
                    </form>
                </template>
            </AppCard>
        </div>
    </main>
</template>
