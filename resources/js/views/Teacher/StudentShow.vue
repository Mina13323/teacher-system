<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppModal from '@/components/ui/AppModal.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import Icon from '@/components/ui/Icon.vue';
import WhatsAppContactModal from '@/components/students/WhatsAppContactModal.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const id = computed(() => {
    const raw = route.params.id;
    return raw && raw !== 'undefined' ? String(raw) : null;
});
const authRole = computed(() => route.path.startsWith('/assistant') ? 'assistant' : 'teacher');

const student = ref(null);
const loading = ref(true);
const error = ref('');

const courses = ref([]);
const selectedCourse = ref('');
const enrolling = ref(false);

// Renew modal state
const showRenewModal = ref(false);
const renewDecision = ref('keep_active');
const renewMonths = ref(1);
const renewAmount = ref('');
const renewNotes = ref('');
const renewBusy = ref(false);

// Reset credentials modal state
const showResetConfirm = ref(false);
const revealCredentials = ref(null);
const resetBusy = ref(false);
const copied = ref(false);

// WhatsApp contact modal. The credentials option is only enabled while a
// one-time reveal payload is held in memory here — it is never refetched.
const showWhatsApp = ref(false);
const resettingWhatsApp = ref(false);

// Explicit staff-initiated reset so the WhatsApp modal's "Send Credentials"
// works even when no one-time reveal is in hand. The new password is shown in
// the modal before anything is sent, so nothing happens silently.
async function whatsappResetAndSend() {
    if (!id.value) return;

    resettingWhatsApp.value = true;
    try {
        const res = await teacher.resetStudentCredentials(id.value);
        const data = res.data || res;
        revealCredentials.value = data.credentials;
        toast.success(t('students.passwordReset') || 'تم إعادة توليد كلمة المرور');
    } catch (e) {
        toast.error(e.message);
    } finally {
        resettingWhatsApp.value = false;
    }
}

async function loadCourses() {
    try {
        const res = toList(await teacher.courses({ per_page: 100 }));
        courses.value = res.items.map((c) => ({ value: c.id, label: c.title }));
    } catch {
        courses.value = [];
    }
}

async function loadStudent() {
    if (!id.value) {
        error.value = 'Student not found.';
        return;
    }
    student.value = await teacher.student(id.value);
}

// Delete modal state
const showDeleteConfirm = ref(false);
const deleteBusy = ref(false);

async function submitDelete() {
    if (!id.value) return;
    deleteBusy.value = true;
    try {
        await teacher.deleteStudent(id.value);
        toast.success(t('students.deleted'));
        router.push(`/${authRole.value}/students`);
    } catch (e) {
        toast.error(e.message);
    } finally {
        deleteBusy.value = false;
    }
}

// Quick Active State Toggle (Unsuspend / Suspend)
const toggleActiveBusy = ref(false);

async function toggleActive(active) {
    if (!id.value) return;
    toggleActiveBusy.value = true;
    try {
        await (active ? teacher.activateStudent : teacher.deactivateStudent)(id.value);
        toast.success(active ? t('students.unsuspendSuccess') : t('students.deactivated'));
        await loadStudent();
    } catch (e) {
        toast.error(e.message);
    } finally {
        toggleActiveBusy.value = false;
    }
}

async function unenroll(courseId) {
    if (!courseId || !id.value) return;
    try {
        await teacher.unenrollStudent(courseId, id.value);
        toast.success(t('students.unenrolled'));
        await loadStudent();
    } catch (e) {
        toast.error(e.message);
    }
}

async function enroll() {
    if (!selectedCourse.value || !id.value) return;
    enrolling.value = true;
    try {
        await teacher.enrollStudent(selectedCourse.value, id.value);
        toast.success(t('students.enrolled'));
        selectedCourse.value = '';
        await loadStudent();
    } catch (e) {
        toast.error(e.message);
    } finally {
        enrolling.value = false;
    }
}

async function submitRenew() {
    if (!id.value) return;
    renewBusy.value = true;
    try {
        const res = await teacher.renewStudent(id.value, {
            decision: renewDecision.value,
            months: renewDecision.value === 'keep_active' ? Number(renewMonths.value) : 1,
            amount: renewAmount.value ? Number(renewAmount.value) : null,
            notes: renewNotes.value || null,
        });

        toast.success(renewDecision.value === 'keep_active' ? t('students.statusActive') : t('students.statusSuspended'));
        showRenewModal.value = false;
        await loadStudent();
    } catch (e) {
        toast.error(e.message);
    } finally {
        renewBusy.value = false;
    }
}

async function submitResetCredentials() {
    if (!id.value) return;
    resetBusy.value = true;
    try {
        const res = await teacher.resetStudentCredentials(id.value);
        const data = res.data || res;
        revealCredentials.value = data.credentials;
        showResetConfirm.value = false;
        toast.success(t('students.passwordReset'));
        await loadStudent();
    } catch (e) {
        toast.error(e.message);
    } finally {
        resetBusy.value = false;
    }
}

function copyAllCredentials() {
    if (!revealCredentials.value) return;
    const creds = revealCredentials.value;
    const text = t('students.credentialsShareText', {
        code: creds.student_code,
        login: creds.login || creds.email,
        password: creds.temporary_password,
    });
    navigator.clipboard.writeText(text);
    copied.value = true;
    toast.success(t('students.copied'));
    setTimeout(() => { copied.value = false; }, 3000);
}

onMounted(async () => {
    try {
        await Promise.all([loadStudent(), loadCourses()]);
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link :to="`/${authRole}/students`" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.students') }}</router-link>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900" dir="auto">{{ student?.name || $t('common.student') }}</h1>
                <span v-if="student?.student_code" class="inline-flex items-center rounded-lg bg-ink-100 px-3 py-1 font-mono text-sm font-bold text-ink-800">
                    {{ student.student_code }}
                </span>
                <span v-if="student?.academic_year" class="inline-flex items-center rounded-lg bg-terracotta-100 px-3 py-1 text-sm font-medium text-terracotta-800">
                    {{ $t(`students.${student.academic_year === 'secondary_1' ? 'secondary1' : student.academic_year === 'secondary_2' ? 'secondary2' : 'secondary3'}`) }}
                </span>
            </div>
            <p class="text-sm text-ink-500 mt-1">{{ student?.email }} {{ student?.phone ? `· ${student.phone}` : '' }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <!-- Overview Cards -->
            <div class="grid gap-4 sm:grid-cols-4">
                <AppCard>
                    <p class="text-xs font-medium text-ink-500 uppercase">{{ $t('students.accessStatus') }}</p>
                    <div class="mt-2">
                        <AppBadge v-if="student.access_status === 'active' || (!student.access_status && student.is_active)" tone="success">
                            {{ $t('students.statusActive') }}
                        </AppBadge>
                        <AppBadge v-else-if="student.access_status === 'due'" tone="warning">
                            {{ $t('students.statusDue') }}
                        </AppBadge>
                        <AppBadge v-else tone="danger">
                            {{ $t('students.statusSuspended') }}
                        </AppBadge>
                    </div>
                </AppCard>

                <AppCard>
                    <p class="text-xs font-medium text-ink-500 uppercase">{{ $t('students.capabilities') }}</p>
                    <div class="mt-2 flex flex-wrap gap-1">
                <span v-if="student.can_access_lessons" class="rounded bg-emerald-50 px-1.5 py-0.5 text-xs text-emerald-700">{{ $t('students.capBadgeLessons') }}</span>
                <span v-if="student.can_take_exams" class="rounded bg-sky-50 px-1.5 py-0.5 text-xs text-sky-700">{{ $t('students.capBadgeExams') }}</span>
                <span v-if="student.can_join_competitions" class="rounded bg-purple-50 px-1.5 py-0.5 text-xs text-purple-700">{{ $t('students.capBadgeCompetitions') }}</span>
                        <span v-if="!student.can_access_lessons && !student.can_take_exams && !student.can_join_competitions" class="text-xs text-ink-400">—</span>
                    </div>
                </AppCard>

                <AppCard>
                    <p class="text-xs font-medium text-ink-500 uppercase">{{ $t('students.profileLabel') }}</p>
                    <AppBadge :tone="student.profile_completed ? 'primary' : 'warning'" class="mt-2">
                        {{ student.profile_completed ? $t('status.complete') : $t('status.incomplete') }}
                    </AppBadge>
                </AppCard>

                <AppCard>
                    <p class="text-xs font-medium text-ink-500 uppercase">{{ $t('students.joined') }}</p>
                    <p class="mt-2 text-sm font-medium text-ink-800">{{ student.created_at ? new Date(student.created_at).toLocaleDateString() : '—' }}</p>
                </AppCard>
            </div>

            <!-- Quick Management Actions -->
            <AppCard :title="$t('students.accountActions')">
                <div class="flex flex-wrap gap-3">
                    <AppButton
                        v-if="student.access_status === 'suspended' || !student.is_active"
                        variant="primary"
                        :loading="toggleActiveBusy"
                        @click="toggleActive(true)"
                    >
                        ✅ {{ $t('students.unsuspend') }}
                    </AppButton>
                    <AppButton
                        v-else
                        variant="outline"
                        class="text-amber-700 hover:bg-amber-50"
                        :loading="toggleActiveBusy"
                        @click="toggleActive(false)"
                    >
                        🚫 {{ $t('students.suspend') }}
                    </AppButton>
                    <AppButton variant="outline" @click="showRenewModal = true">
                        🔄 {{ $t('students.renewAccess') }}
                    </AppButton>
                    <AppButton variant="outline" @click="showResetConfirm = true">
                        🔑 {{ $t('students.resetCredentials') }}
                    </AppButton>
                    <AppButton
                        variant="outline"
                        class="border-emerald-300 text-emerald-700 hover:bg-emerald-50"
                        :disabled="!student?.whatsapp_phone"
                        @click="showWhatsApp = true"
                    >
                        <Icon name="whatsapp" :size="16" class="me-1 inline-block align-[-3px]" />
                        {{ $t('whatsapp.contactStudent') }}
                    </AppButton>
                    <router-link :to="`/${authRole}/students/${student.id}/edit`">
                        <AppButton variant="ghost">{{ $t('common.edit') }}</AppButton>
                    </router-link>
                    <router-link v-if="authRole === 'teacher'" :to="`/teacher/analytics/students/${student.id}`">
                        <AppButton variant="secondary">{{ $t('students.viewAnalytics') }}</AppButton>
                    </router-link>
                    <AppButton
                        variant="ghost"
                        class="text-rose-600 hover:text-rose-700 hover:bg-rose-50"
                        @click="showDeleteConfirm = true"
                    >
                        🗑️ {{ $t('common.delete') }}
                    </AppButton>
                </div>
            </AppCard>

            <!-- Course Enrollments -->
            <AppCard :title="$t('students.enrollIntoCourse')">
                <!-- Current Enrollments List -->
                <div v-if="student.enrollments && student.enrollments.length" class="mb-4 space-y-2">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-ink-500">{{ $t('students.courses') }}</label>
                    <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-ink-50/30">
                        <div v-for="e in student.enrollments" :key="e.id" class="flex items-center justify-between p-3">
                            <div>
                                <p class="text-sm font-medium text-ink-900" dir="auto">{{ e.course?.title || e.course_title || '—' }}</p>
                                <p class="text-xs text-ink-500">{{ $t('students.enrolledAt') }}: {{ e.enrolled_at ? new Date(e.enrolled_at).toLocaleDateString() : '—' }}</p>
                            </div>
                            <AppButton variant="ghost" size="sm" class="text-rose-600 hover:bg-rose-50" @click="unenroll(e.course_id || e.course?.id)">
                                {{ $t('students.unenroll') }}
                            </AppButton>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <AppSelect v-model="selectedCourse" :label="$t('common.course')" :options="courses" id="enroll-course" :placeholder="$t('common.selectCourse')" class="flex-1" />
                    <AppButton :loading="enrolling" :disabled="!selectedCourse" @click="enroll">{{ $t('students.enroll') }}</AppButton>
                </div>
                <p v-if="!courses.length" class="mt-3 text-sm text-ink-400">{{ $t('students.noCoursesToEnroll') }}</p>
            </AppCard>
        </template>

        <!-- Renew Access Modal -->
        <AppModal :open="showRenewModal" :title="$t('students.renewAccess')" size="sm" @close="showRenewModal = false">
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
                    <AppInput v-model="renewMonths" type="number" min="1" max="12" :label="$t('students.renewalMonths')" id="detail-renew-months" />
                    <AppInput v-model="renewAmount" type="number" min="0" step="0.5" :label="$t('students.renewalAmount')" id="detail-renew-amount" placeholder="e.g. 200" />
                </div>

                <AppTextarea v-model="renewNotes" :label="$t('students.renewalNotes')" id="detail-renew-notes" :rows="2" placeholder="$t('students.renewalNotesPlaceholder')" />

                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="renewBusy" @click="showRenewModal = false">{{ $t('common.cancel') }}</AppButton>
                    <AppButton :loading="renewBusy" @click="submitRenew">{{ $t('common.saveChanges') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Confirm Reset Modal -->
        <AppModal :open="showResetConfirm" :title="$t('students.resetCredentials')" size="sm" @close="showResetConfirm = false">
            <div class="space-y-4">
                <p class="text-sm text-ink-700">
                    {{ $t('students.regenConfirm') }} <strong>{{ student?.name }}</strong>?
                </p>
                <p class="text-xs text-rose-600">
                    {{ $t('students.regenConfirmBody') }}
                </p>
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="resetBusy" @click="showResetConfirm = false">{{ $t('common.cancel') }}</AppButton>
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
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('auth.email') }} / {{ $t('students.loginLabel') }}</span>
                        <span class="text-sm font-mono text-ink-900 select-all">{{ revealCredentials.login || revealCredentials.email }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('auth.password') }} ({{ $t('students.tempPasswordLabel') }})</span>
                        <span class="text-base font-mono font-bold text-ink-900 bg-white border border-ink-200 px-3 py-1.5 rounded-lg inline-block select-all">{{ revealCredentials.temporary_password }}</span>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <AppButton variant="outline" @click="copyAllCredentials">
                        <span v-if="copied">✓ {{ $t('students.copied') }}</span>
                        <span v-else>📋 {{ $t('students.copyCredentials') }}</span>
                    </AppButton>
                    <!-- Straight from the one-time reveal into WhatsApp. The
                         reveal payload stays in memory, so the credentials
                         template becomes available. Nothing is auto-sent. -->
                    <AppButton
                        variant="outline"
                        class="border-emerald-300 text-emerald-700 hover:bg-emerald-50"
                        :disabled="!student?.whatsapp_phone"
                        @click="showWhatsApp = true"
                    >
                        <Icon name="whatsapp" :size="16" class="me-1 inline-block align-[-3px]" />
                        {{ $t('whatsapp.sendCredentials') }}
                    </AppButton>
                    <AppButton @click="revealCredentials = null">{{ $t('common.confirm') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Delete Student Modal -->
        <AppModal :open="showDeleteConfirm" :title="$t('students.deleteStudent')" size="sm" @close="showDeleteConfirm = false">
            <div class="space-y-4">
                <p class="text-sm text-ink-700" dir="auto">
                    {{ $t('students.deleteStudentConfirm', { name: student?.name || '' }) }}
                </p>
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton variant="outline" :disabled="deleteBusy" @click="showDeleteConfirm = false">{{ $t('common.cancel') }}</AppButton>
                    <AppButton variant="danger" :loading="deleteBusy" @click="submitDelete">{{ $t('common.delete') }}</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- WhatsApp contact -->
        <WhatsAppContactModal
            :open="showWhatsApp"
            :student="student"
            :credentials="revealCredentials"
            :resetting="resettingWhatsApp"
            @reset="whatsappResetAndSend"
            @close="showWhatsApp = false"
        />
    </div>
</template>
