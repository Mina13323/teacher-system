<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { student, toList } from '@/api';
import { useToast } from '@/composables/toast';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppInput from '@/components/ui/AppInput.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';

/**
 * Student certificates (P2): my issued certificates, one-tap issuance for
 * completed courses (the server decides eligibility), and a verification box
 * that resolves any code to its minimum public record.
 */
const { t } = useI18n();
const toast = useToast();

const loading = ref(true);
const certificates = ref([]);
const courses = ref([]);
const busyCourseId = ref(null);

const verifyCode = ref('');
const verifyBusy = ref(false);
const verifyResult = ref(null);
const verifyError = ref('');

function fmtDate(value) {
    if (!value) return '—';
    const dt = new Date(value);
    return Number.isNaN(dt.getTime()) ? value : dt.toLocaleDateString();
}

async function load() {
    loading.value = true;
    try {
        const [certs, myCourses] = await Promise.all([
            student.certificates({ per_page: 50 }),
            student.courses ? toList(await student.courses()) : { items: [] },
        ]);
        certificates.value = toList(certs).items;
        courses.value = myCourses.items;
    } catch (e) {
        toast.error(e.message);
    } finally {
        loading.value = false;
    }
}

async function claim(courseId) {
    busyCourseId.value = courseId;
    try {
        await student.issueCertificate(courseId);
        toast.success(t('certificates.claimed'));
        await load();
    } catch (e) {
        toast.error(e.message);
    } finally {
        busyCourseId.value = null;
    }
}

async function verify() {
    if (!verifyCode.value.trim()) return;
    verifyBusy.value = true;
    verifyResult.value = null;
    verifyError.value = '';
    try {
        verifyResult.value = await student.verifyCertificate(verifyCode.value.trim());
    } catch (e) {
        verifyError.value = t('certificates.verifyNotFound');
    } finally {
        verifyBusy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <h1 class="text-2xl font-bold text-ink-900">{{ $t('nav.certificates') }}</h1>

        <!-- My certificates -->
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-ink-800">{{ $t('certificates.mine') }}</h2>
            <LoadingSpinner v-if="loading" />
            <EmptyState v-else-if="!certificates.length" icon="award" :title="$t('certificates.emptyTitle')" :message="$t('certificates.emptyMessage')" />
            <div v-else class="grid gap-3 sm:grid-cols-2">
                <div v-for="c in certificates" :key="c.id" class="rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
                    <p class="font-semibold text-ink-800" dir="auto">{{ c.course?.title }}</p>
                    <p class="mt-1 text-xs text-ink-400">{{ $t('certificates.issued') }}: {{ fmtDate(c.issued_at) }}</p>
                    <p class="mt-2 font-mono text-xs text-ink-600">{{ c.code }}</p>
                    <AppBadge tone="success" class="mt-2">{{ $t('certificates.verified') }}</AppBadge>
                </div>
            </div>
        </section>

        <!-- Claim eligible courses -->
        <section v-if="courses.length" class="space-y-3">
            <h2 class="text-lg font-semibold text-ink-800">{{ $t('certificates.claimTitle') }}</h2>
            <div class="space-y-2">
                <div v-for="c in courses" :key="c.id" class="flex flex-wrap items-center gap-3 rounded-xl border border-ink-100 bg-white px-4 py-3 shadow-sm">
                    <p class="min-w-0 flex-1 font-medium text-ink-800" dir="auto">{{ c.title }}</p>
                    <AppButton size="sm" variant="outline" :loading="busyCourseId === c.id" @click="claim(c.id)">
                        {{ $t('certificates.claimBtn') }}
                    </AppButton>
                </div>
            </div>
        </section>

        <!-- Verify any code -->
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-ink-800">{{ $t('certificates.verifyTitle') }}</h2>
            <div class="flex flex-wrap items-end gap-2">
                <AppInput v-model="verifyCode" :label="$t('certificates.codeLabel')" placeholder="CERT-..." id="verify-code" class="flex-1 min-w-[16rem]" />
                <AppButton :loading="verifyBusy" @click="verify">{{ $t('certificates.verifyBtn') }}</AppButton>
            </div>
            <div v-if="verifyResult" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm">
                <p class="font-semibold text-emerald-800">✓ {{ $t('certificates.verifyValid') }}</p>
                <p class="mt-1 text-emerald-900">{{ $t('certificates.student') }}: {{ verifyResult.student_name }}</p>
                <p class="text-emerald-900">{{ $t('certificates.course') }}: {{ verifyResult.course_title }}</p>
                <p class="text-emerald-900">{{ $t('certificates.issued') }}: {{ fmtDate(verifyResult.issued_at) }}</p>
            </div>
            <p v-else-if="verifyError" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{{ verifyError }}</p>
        </section>
    </div>
</template>
