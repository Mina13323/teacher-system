<script setup>
import { computed, onMounted, ref } from 'vue';
import { formatDateTime } from '@/utils/format';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { student } from '@/api';
import { isAmbiguousStartFailure, recoverActiveAttempt } from '@/utils/examStartRecovery';
import { clearAttemptHandoff, handOffAttempt } from '@/utils/attemptHandoff';
import { startPacingDelayMs } from '@/utils/examRequestPacing';
import { createServerClock } from '@/utils/serverClock';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppModal from '@/components/ui/AppModal.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const starting = ref(false);
const resuming = ref(false);
const resumeAttemptId = ref(null);
const startNotice = ref('');
const rulesOpen = ref(false);
const rulesAccepted = ref(false);
/** True while a paced start waits before sending (see startPacingDelayMs). */
const preparing = ref(false);

const { loading, error, data, run } = useAsync(() => student.exam(route.params.id));
const activeAttempt = computed(() => (
    data.value?.my_attempts?.find((attempt) => attempt.status === 'in_progress' && attempt.id)
    || (resumeAttemptId.value ? { id: resumeAttemptId.value, status: 'in_progress' } : null)
));
onMounted(() => run().catch(() => null));

const activeRuleLabels = computed(() => {
    const rules = data.value?.integrity_rules || {};
    return [
        ['fullscreen_required', 'examTake.ruleFullscreen'],
        ['prevent_copy', 'examTake.ruleCopy'],
        ['prevent_paste', 'examTake.rulePaste'],
        ['prevent_context_menu', 'examTake.ruleContextMenu'],
        ['detect_tab_switch', 'examTake.ruleTabSwitch'],
        ['detect_window_blur', 'examTake.ruleWindowBlur'],
        ['detect_keyboard_shortcuts', 'examTake.ruleShortcuts'],
    ].filter(([key]) => Boolean(rules[key])).map(([, label]) => t(label));
});

const integrityConsequence = computed(() => {
    const rules = data.value?.integrity_rules || {};
    if (!activeRuleLabels.value.length) return '';

    return rules.terminate_on_violation
        ? t('examTake.rulesTermination', { n: rules.violation_warning_threshold ?? 5 })
        : t('examTake.rulesMonitoringOnly');
});

/**
 * Display-only classification of the exam window. The server re-checks this on
 * every start and is the only authority; this just picks the right message and
 * never decides whether the student may actually begin.
 */
function windowState() {
    const d = data.value;
    if (!d) return 'none';
    const now = Date.now();
    if (d.starts_at && now < new Date(d.starts_at).getTime()) return 'not_open';
    const deadline = d.ends_at || d.effective_deadline;
    if (deadline && now > new Date(deadline).getTime()) return 'closed';
    return 'open';
}

function start() {
    startNotice.value = '';
    rulesAccepted.value = false;
    rulesOpen.value = true;
}

async function navigateToAttempt(attemptId) {
    if (!attemptId) return false;

    const id = String(attemptId);
    try {
        await router.push({ name: 'student.attempt', params: { id } });
    } catch {
        return false;
    }

    const current = router.currentRoute.value;
    return current.name === 'student.attempt' && String(current.params.id) === id;
}

async function resumeAttempt() {
    const attemptId = activeAttempt.value?.id;
    if (!attemptId || resuming.value) return;

    resuming.value = true;
    startNotice.value = '';
    try {
        if (!await navigateToAttempt(attemptId)) {
            startNotice.value = t('exams.attemptNavigationFailed');
        }
    } finally {
        resuming.value = false;
    }
}

/**
 * Random wait before the start request while a scheduled exam is opening, on
 * the server's clock (the time endpoint makes no database query). 0 outside
 * the opening burst and whenever a wait could cost exam time.
 */
async function startPacingDelay() {
    const d = data.value;
    if (!d?.starts_at) return 0;
    const clock = createServerClock();
    try {
        const sentAt = Date.now();
        const res = await student.serverTime();
        clock.observe(Number(res?.server_time_ms), sentAt, Date.now());
    } catch {
        /* the device clock is used when the server clock is unavailable */
    }
    return startPacingDelayMs({
        nowMs: clock.serverNow(),
        startsAt: d.starts_at,
        endsAt: d.ends_at || d.effective_deadline || null,
        durationMinutes: d.duration_minutes,
    });
}

async function confirmStart() {
    if (!rulesAccepted.value || starting.value) return;

    starting.value = true;
    startNotice.value = '';
    try {
        const wait = await startPacingDelay();
        if (wait > 0) {
            preparing.value = true;
            await new Promise((resolve) => setTimeout(resolve, wait));
            preparing.value = false;
        }

        // The full attempt snapshot comes back with the start response and is
        // handed to the exam screen, so it does not read the attempt again.
        const attempt = await student.startExam(route.params.id, {
            rules_acknowledged: true,
        });

        if (!attempt?.id) {
            const error = new Error('The start response did not include an attempt ID.');
            error.status = 0;
            error.isNetwork = true;
            throw error;
        }

        resumeAttemptId.value = attempt.id;
        if (attempt.already_open) {
            // Another session has this attempt open and may be changing it:
            // the exam screen reads it fresh from the server instead.
            clearAttemptHandoff();
            toast.success(t('exams.alreadyOpen'));
        } else {
            handOffAttempt(attempt);
        }

        rulesOpen.value = false;
        if (!await navigateToAttempt(attempt.id)) {
            clearAttemptHandoff();
            startNotice.value = t('exams.attemptNavigationFailed');
        }
    } catch (e) {
        const state = windowState();
        if (e?.status === 422 && (state === 'not_open' || state === 'closed')) {
            startNotice.value = t(state === 'not_open' ? 'exams.windowNotOpenNotice' : 'exams.windowClosedNotice');
            await run().catch(() => null);
        } else if (isAmbiguousStartFailure(e)) {
            const active = await recoverActiveAttempt(student, route.params.id, e);
            if (active?.id) {
                resumeAttemptId.value = active.id;
                rulesOpen.value = false;
                if (!await navigateToAttempt(active.id)) {
                    startNotice.value = t('exams.attemptNavigationFailed');
                }
            } else {
                startNotice.value = t('exams.startUnconfirmed');
            }
        } else {
            toast.error(e?.message || '');
        }
    } finally {
        preparing.value = false;
        starting.value = false;
    }
}

function fmtWhen(iso) {
    return formatDateTime(iso);
}

function attemptTone(status) {
    return { submitted: 'success', in_progress: 'warning', expired: 'danger' }[status] || 'neutral';
}
function statusLabel(status) {
    return status ? t(`status.${status}`, status) : '';
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <p>{{ error.message }}</p>
            <AppButton class="mt-3" size="sm" variant="outline" @click="run().catch(() => null)">{{ $t('common.retry') }}</AppButton>
        </div>
        <template v-else>
            <div>
                <router-link to="/student/exams" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('exams.allExams') }}</router-link>
                <h1 class="mt-2 text-2xl font-bold text-ink-900" dir="auto">{{ data.title }}</h1>
                <p class="mt-1 text-ink-600" dir="auto">{{ data.description }}</p>
            </div>

            <AppCard :title="$t('exams.about')">
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-ink-400">{{ $t('exams.duration') }}</dt><dd class="font-semibold text-ink-800">{{ data.duration_minutes }} {{ $t('common.minutesShort') }}</dd></div>
                    <div><dt class="text-ink-400">{{ $t('exams.questions') }}</dt><dd class="font-semibold text-ink-800">{{ data.questions_count }}</dd></div>
                    <div><dt class="text-ink-400">{{ $t('exams.passMark') }}</dt><dd class="font-semibold text-ink-800">{{ data.pass_percentage }}%</dd></div>
                    <div><dt class="text-ink-400">{{ $t('exams.attempts') }}</dt><dd class="font-semibold text-ink-800">{{ data.max_attempts }}</dd></div>
                </dl>
                <!-- Official window, shown when starts_at or ends_at is configured -->
                <div v-if="data.make_up_available" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                    {{ $t('exams.makeUpAvailable') }}
                </div>

                <div v-if="data.starts_at || data.ends_at || data.effective_deadline" class="mt-4 rounded-lg border border-ink-200 bg-ink-50/60 px-4 py-3 text-sm">
                    <div class="flex flex-wrap gap-x-6 gap-y-1">
                        <span v-if="data.starts_at" class="text-ink-500">{{ $t('exams.opensAt') }}: <span class="font-semibold text-ink-800">{{ fmtWhen(data.starts_at) }}</span></span>
                        <span v-if="data.ends_at || data.effective_deadline" class="text-ink-500">{{ $t('exams.deadline') }}: <span class="font-semibold text-ink-800">{{ fmtWhen(data.ends_at || data.effective_deadline) }}</span></span>
                    </div>
                    <p class="mt-1 text-xs text-ink-500">{{ $t('exams.windowStudentHint') }}</p>
                </div>

                <div v-if="startNotice && !rulesOpen" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900" role="alert">
                    {{ startNotice }}
                </div>

                <div v-if="activeAttempt" class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="status">
                    {{ $t('exams.activeAttemptHint') }}
                </div>

                <div class="mt-6">
                    <AppButton v-if="activeAttempt" :loading="resuming" size="lg" @click="resumeAttempt">{{ $t('exams.resumeAttempt') }}</AppButton>
                    <AppButton v-else :loading="starting" size="lg" @click="start">{{ $t('exams.startExam') }}</AppButton>
                </div>
            </AppCard>

            <AppCard v-if="data.my_attempts?.length" :title="$t('exams.yourAttempts')">
                <div class="divide-y divide-ink-100">
                    <div v-for="a in data.my_attempts" :key="a.attempt_number" class="flex items-center gap-3 py-3">
                        <span class="text-sm font-semibold text-ink-700">{{ $t('common.attemptN', { n: a.attempt_number }) }}</span>
                        <AppBadge :tone="attemptTone(a.status)">{{ statusLabel(a.status) }}</AppBadge>
                        <span v-if="a.percentage !== null" class="text-sm text-ink-600">{{ a.percentage }}%</span>
                        <span class="ms-auto text-xs text-ink-400">{{ formatDateTime(a.submitted_at || a.started_at) }}</span>
                    </div>
                </div>
            </AppCard>
        </template>

        <AppModal :open="rulesOpen" :title="$t('examTake.rulesTitle')" :close-on-backdrop="false" @close="rulesOpen = false">
            <div class="space-y-4 text-sm text-ink-700">
                <p class="leading-relaxed">{{ $t('examTake.rulesIntro') }}</p>
                <ul class="list-disc space-y-2 ps-5">
                    <li>{{ $t('examTake.rulesClock') }}</li>
                    <li>{{ $t('examTake.rulesMonitoring') }}</li>
                    <li v-if="integrityConsequence">{{ integrityConsequence }}</li>
                    <li v-if="activeRuleLabels.length" class="list-none -ms-5">
                        <p class="mb-1 font-medium">{{ $t('examTake.rulesEnabled') }}</p>
                        <ul class="list-disc space-y-1 ps-5 text-ink-600">
                            <li v-for="rule in activeRuleLabels" :key="rule">{{ rule }}</li>
                        </ul>
                    </li>
                    <li v-else>{{ $t('examTake.rulesNoExtraControls') }}</li>
                    <li class="font-medium text-amber-800">{{ $t('examTake.screenshotLimit') }}</li>
                </ul>
                <p v-if="preparing" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-3 text-sm font-medium text-sky-900" role="status">
                    {{ $t('exams.preparingExam') }}
                </p>
                <p v-if="startNotice && rulesOpen" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm font-medium text-amber-900" role="alert">
                    {{ startNotice }}
                </p>
                <label class="flex items-start gap-2 rounded-lg border border-ink-200 bg-ink-50 px-3 py-3 text-sm font-medium text-ink-800">
                    <input v-model="rulesAccepted" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" />
                    <span>{{ $t('examTake.rulesAcknowledge') }}</span>
                </label>
            </div>
            <template #footer>
                <AppButton variant="outline" :disabled="starting" @click="rulesOpen = false">{{ $t('examTake.rulesCancel') }}</AppButton>
                <AppButton :loading="starting" :disabled="!rulesAccepted" @click="confirmStart">{{ $t('examTake.rulesStart') }}</AppButton>
            </template>
        </AppModal>
    </div>
</template>
