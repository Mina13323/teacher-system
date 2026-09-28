<script setup>
import { onMounted, ref } from 'vue';
import { teacher } from '@/api';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';

const link = ref(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');

async function load() {
    loading.value = true;
    try { link.value = await teacher.studentRegistrationLink(); } catch (e) { error.value = e.message; } finally { loading.value = false; }
}
async function rotate() {
    busy.value = true;
    try { link.value = await teacher.rotateStudentRegistrationLink(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function toggle() {
    busy.value = true;
    try { link.value = await teacher.setStudentRegistrationLinkActive(!link.value.is_active); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
function copy() { navigator.clipboard.writeText(link.value.url); }
onMounted(load);
</script>

<template>
    <AppCard title="Student self-registration link">
        <p class="text-sm text-ink-600">Students enter their name, phone number, and academic year. Their username and temporary password are generated automatically. Third-secondary students default to both subjects.</p>
        <p v-if="error" class="mt-4 text-sm text-rose-700">{{ error }}</p>
        <template v-else-if="!loading && link">
            <input readonly :value="link.url" class="mt-5 w-full rounded-lg border border-ink-200 bg-ink-50 p-3 text-sm" />
            <div class="mt-4 flex flex-wrap gap-3">
                <AppButton @click="copy">Copy link</AppButton>
                <AppButton variant="outline" :loading="busy" @click="toggle">{{ link.is_active ? 'Disable link' : 'Enable link' }}</AppButton>
                <AppButton variant="outline" :loading="busy" @click="rotate">Create new link</AppButton>
            </div>
        </template>
    </AppCard>
</template>
