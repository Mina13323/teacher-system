<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { useExamIntegrity } from '@/composables/useExamIntegrity';
import { student } from '@/api';
import { useToast } from '@/composables/toast';
import { useNotificationsStore } from '@/stores/notifications';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const notifications = useNotificationsStore();

const attempt = ref(null);
const result = ref(null);
const current = ref(0);
const essayAnswers = ref({});
const savingAnswer = ref(false);
const submitting = ref(false);
const submittingBusy = ref(false);
const confirmOpen = ref(false);
const timeLeft = ref(0);
const expired = ref(false);
let timer = null;

/**
 * True once the attempt can no longer be mutated. Driven by the server's own
 * view of the attempt (never by the local clock alone), so a skewed device
 * clock cannot keep the UI editable after the server has closed the attempt.
 */
const blocked = computed(() => expired.value || attempt.value?.status === 'expired' || attempt.value?.status === 'submitted');

/**
 * Proctoring. The rules come from the server's frozen per-attempt settings, so
 * the student cannot switch monitoring off from the client. When the exam is
 * configured to terminate on a violation, leaving the tab, backgrounding the
 * app or dropping fullscreen submits the attempt immediately.
 */
const integrityRules = computed(() => attempt.value?.integrity_rules || null);
const terminatedByIntegrity = ref(false);

const {
    start: startMonitoring,
    stop: stopMonitoring,
    requestFullscreen,
    fullscreenActive,
    violations,
} = useExamIntegrity({
    getAttemptId: () => attempt.value?.id ?? null,
    getRules: () => integrityRules.value,
    onTerminate: (type) => terminateExam(type),
});

/**
 * Ends the attempt because the student left the exam screen. Goes through the
 * same submit endpoint as a normal submission, so the server grades it and
 * applies the usual expiry rules — the client never decides the outcome.
 */
async function terminateExam(type) {
    if (!attempt.value || attempt.value.status !== 'in_progress') return;

    stopMonitoring();
    terminatedByIntegrity.value = true;
    submittingBusy.value = true;

    try {
        const res = await student.submit(attempt.value.id);
        result.value = res;
        notifications.refreshUnread();
        toast.error(t('examTake.integrityTerminated'));
    } catch (e) {
        expired.value = true;
        await handleRejection(e);
    } finally {
        submittingBusy.value = false;
    }
}

function beginMonitoring() {
    if (attempt.value?.status !== 'in_progress') return;
    startMonitoring();
}

const { loading, error, run: load } = useAsync(async () => {
    const a = await student.attempt(route.params.id);
    attempt.value = normalizeAttempt(a);
    initEssayAnswers();
    // A student returning to an attempt they already finished (reload, or the
    // link from a result notification) must see the outcome. Previously the
    // result panel was only ever populated by the submit response, so it sat
    // on "under review" forever — even after the grades were published.
    if (a && a.status !== 'in_progress' && a.status !== 'expired') {
        result.value = a;
    }
    startTimer();
    beginMonitoring();
});

function normalizeAttempt(a) {
    if (!a) return a;
    const qs = Array.isArray(a.questions) ? a.questions : (a.questions?.data || []);
    return {
        ...a,
        questions: qs.map((q) => ({
            ...q,
            options: Array.isArray(q.options) ? q.options : (q.options?.data || []),
        })),
    };
}

function initEssayAnswers() {
    if (!attempt.value?.questions) return;
    attempt.value.questions.forEach((q) => {
        if (q.question_type === 'essay') {
            essayAnswers.value[q.id] = q.answer_text || '';
        }
    });
}

const questions = computed(() => normalizeAttempt(attempt.value)?.questions || []);
const currentQuestion = computed(() => questions.value[current.value]);

const answeredCount = computed(() => {
    return questions.value.filter((q) => {
        if (q.question_type === 'essay') {
            return Boolean((essayAnswers.value[q.id] || q.answer_text || '').trim());
        }
        return q.options.some((o) => o.selected);
    }).length;
});

const confirmMessage = computed(() => t('examTake.confirmMessage', { n: answeredCount.value, total: questions.value.length }));

function startTimer() {
    const expires = attempt.value?.expires_at;
    if (!expires) return;
    const tick = () => {
        const ms = new Date(expires).getTime() - Date.now();
        timeLeft.value = ms <= 0 ? 0 : ms;
        if (ms <= 0) {
            clearInterval(timer);
            onTimeUp();
        }
    };
    tick();
    timer = setInterval(tick, 1000);
}

function fmt(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return `${h ? h + ':' : ''}${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
}

/**
 * A 422 means the server refused the mutation — in this flow almost always
 * because the attempt has expired. Re-read the attempt so the UI reflects the
 * server's truth, and lock the screen if it is no longer editable.
 */
async function handleRejection(e) {
    if (e?.status === 422) {
        try {
            const fresh = normalizeAttempt(await student.attempt(attempt.value.id));
            attempt.value = fresh;
            if (fresh?.status === 'expired' || fresh?.status === 'submitted') {
                expired.value = true;
                stopMonitoring();
                toast.error(t('examTake.expiredBlocked'));
                return;
            }
        } catch {
            /* fall through to the generic message */
        }
    }
    toast.error(e?.message || t('examTake.timeExpired', { message: '' }));
}

async function answer(optionId) {
    const q = currentQuestion.value;
    if (blocked.value || attempt.value?.status !== 'in_progress') return;
    try {
        const updated = await student.answer(attempt.value.id, { question_id: q.id, option_id: optionId });
        attempt.value = normalizeAttempt(updated);
    } catch (e) {
        await handleRejection(e);
    }
}

async function saveEssay(qId) {
    if (blocked.value || attempt.value?.status !== 'in_progress') return;
    savingAnswer.value = true;
    try {
        const text = essayAnswers.value[qId] || '';
        const updated = await student.answer(attempt.value.id, { question_id: qId, answer_text: text });
        attempt.value = normalizeAttempt(updated);
        toast.success(t('examTake.essaySaved'));
    } catch (e) {
        await handleRejection(e);
    } finally {
        savingAnswer.value = false;
    }
}

async function submit() {
    submitting.value = true;
    confirmOpen.value = false;
    stopMonitoring();
    try {
        const res = await student.submit(attempt.value.id);
        result.value = res;
        notifications.refreshUnread();
    } catch (e) {
        await handleRejection(e);
    } finally {
        submitting.value = false;
    }
}

async function onTimeUp() {
    submittingBusy.value = true;
    stopMonitoring();
    try {
        const res = await student.submit(attempt.value.id);
        result.value = res;
        toast.info(t('examTake.timeUp'));
    } catch (e) {
        // The countdown is display-only; the server decides. Lock the screen
        // and surface the translated expiration notice.
        expired.value = true;
        await handleRejection(e);
    } finally {
        submittingBusy.value = false;
    }
}

function finish() {
    router.push('/student/exams');
}

onMounted(() => load());
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <!-- Result / submitted state -->
        <div v-else-if="result || (attempt && attempt.status !== 'in_progress' && attempt.status !== 'expired')" class="space-y-4">
            <div class="rounded-xl border border-ink-100 bg-white p-8 text-center shadow-sm space-y-4">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <h1 class="text-2xl font-bold text-ink-900">
                    {{ result ? $t('examTake.submitted') : (attempt.status === 'expired' ? $t('examTake.expired') : $t('examTake.completed')) }}
                </h1>

                <div v-if="terminatedByIntegrity" class="rounded-xl border border-rose-200 bg-rose-50 p-4 max-w-md mx-auto text-sm text-rose-800">
                    <p class="font-bold mb-1">🛑 {{ $t('examTake.integrityTerminatedTitle') }}</p>
                    <p class="text-xs leading-relaxed">{{ $t('examTake.integrityTerminatedBody') }}</p>
                </div>

                <!-- Score if published -->
                <div v-if="result?.grades_published || (result?.score !== null && result?.score !== undefined)">
                    <p class="text-ink-600">
                        {{ $t('examTake.youScored') }} <span class="font-bold text-ink-900">{{ result.percentage }}%</span>
                    </p>
                    <AppBadge v-if="result?.passed !== null && result?.passed !== undefined" :tone="result.passed ? 'success' : 'danger'" class="mt-3">
                        {{ result.passed ? $t('status.passed') : $t('status.failed') }}
                    </AppBadge>
                </div>

                <!-- Unreleased grade message -->
                <div v-else class="rounded-xl border border-amber-200 bg-amber-50 p-4 max-w-md mx-auto text-sm text-amber-900">
                    <p class="font-bold text-base mb-1">⏳ {{ $t('examTake.gradingTitle') }}</p>
                    <p class="text-xs text-amber-800">{{ $t('examTake.gradingBody') }}</p>
                </div>

                <div class="pt-2"><AppButton @click="finish">{{ $t('examTake.backToExams') }}</AppButton></div>
            </div>
        </div>

        <!-- Active attempt -->
        <template v-else-if="attempt">
            <div class="flex items-center justify-between rounded-xl border border-ink-100 bg-white px-5 py-4 shadow-sm">
                <div>
                    <h1 class="text-lg font-semibold text-ink-900" dir="auto">{{ attempt.exam_title }}</h1>
                    <p class="text-xs text-ink-400">{{ $t('common.attemptN', { n: attempt.attempt_number }) }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <AppBadge :tone="timeLeft < 60000 ? 'danger' : 'primary'">⏱ {{ fmt(timeLeft) }}</AppBadge>
                    <span class="text-xs text-ink-400">{{ $t('examTake.answered', { n: answeredCount, total: questions.length }) }}</span>
                </div>
            </div>

            <!-- Server-authoritative expiration notice: blocks all mutation -->
            <div v-if="blocked" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700" role="alert">
                ⏱ {{ $t('examTake.expiredBlocked') }}
            </div>

            <!-- Proctoring notice: states what is monitored and what ends the attempt -->
            <div
                v-if="integrityRules && !blocked"
                class="rounded-xl border px-4 py-3 text-sm"
                :class="integrityRules.terminate_on_violation ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-amber-200 bg-amber-50 text-amber-900'"
                role="status"
            >
                <p class="font-semibold mb-1">🛡 {{ $t('examTake.integrityNoticeTitle') }}</p>
                <p class="text-xs leading-relaxed">
                    {{ integrityRules.terminate_on_violation ? $t('examTake.integrityStrictBody') : $t('examTake.integrityMonitorBody') }}
                </p>
                <p v-if="violations" class="text-xs mt-1.5 font-semibold">
                    ⚠️ {{ $t('examTake.integrityViolationCount', { n: violations }) }}
                </p>
            </div>

            <!-- Fullscreen requirement -->
            <div
                v-if="integrityRules?.fullscreen_required && !fullscreenActive && !blocked"
                class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 flex flex-wrap items-center justify-between gap-2"
            >
                <span>{{ $t('examTake.fullscreenRequired') }}</span>
                <AppButton size="sm" variant="danger" @click="requestFullscreen()">
                    {{ $t('examTake.enterFullscreen') }}
                </AppButton>
            </div>

            <!-- Question Card -->
            <div class="rounded-xl border border-ink-100 bg-white p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between text-sm text-ink-500">
                    <span>{{ $t('examTake.questionOf', { n: current + 1, total: questions.length }) }}</span>
                    <span>{{ currentQuestion?.points }} {{ $t('examTake.pts') }}</span>
                </div>
                <p class="mt-2 text-lg font-medium text-ink-900" dir="auto">{{ currentQuestion?.question_text }}</p>
                <img v-if="currentQuestion?.image_url" :src="currentQuestion.image_url" :alt="$t('examTake.questionImageAlt')" class="max-h-[28rem] w-full rounded-lg border border-ink-200 object-contain bg-ink-50" />

                <!-- Essay Question Input -->
                <div v-if="currentQuestion?.question_type === 'essay'" class="space-y-3 pt-2">
                    <AppTextarea
                        v-model="essayAnswers[currentQuestion.id]"
                        :label="$t('examTake.essayLabel')"
                        id="essay-input"
                        :rows="6"
                        :placeholder="$t('examTake.essayPlaceholder')"
                        :disabled="blocked"
                        dir="auto"
                    />
                    <div class="flex justify-end">
                        <AppButton size="sm" variant="outline" :loading="savingAnswer" @click="saveEssay(currentQuestion.id)">
                            💾 {{ $t('examTake.saveEssay') }}
                        </AppButton>
                    </div>
                </div>

                <!-- MCQ Options -->
                <div v-else class="space-y-2 pt-2">
                    <button
                        v-for="opt in currentQuestion?.options"
                        :key="opt.id"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg border px-4 py-3 text-start transition focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400 disabled:cursor-not-allowed disabled:opacity-60"
                        :class="opt.selected ? 'border-terracotta-500 bg-terracotta-50' : 'border-ink-200 hover:border-ink-300 hover:bg-ink-50'"
                        :disabled="blocked"
                        @click="answer(opt.id)"
                    >
                        <span class="flex h-5 w-5 items-center justify-center rounded-full border" :class="opt.selected ? 'border-terracotta-500 bg-terracotta-500 text-white' : 'border-ink-300'">
                            <svg v-if="opt.selected" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <span class="text-ink-800 font-medium" dir="auto">{{ opt.option_text }}</span>
                    </button>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex items-center justify-between gap-3">
                <AppButton variant="outline" :disabled="current === 0" @click="current--">{{ $t('common.previous') }}</AppButton>
                <div class="flex flex-wrap justify-center gap-1.5">
                    <button
                        v-for="(q, i) in questions"
                        :key="q.id"
                        type="button"
                        class="h-9 w-9 rounded-lg text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400"
                        :class="[i === current ? 'bg-terracotta-600 text-white' : (q.question_type === 'essay' ? Boolean(essayAnswers[q.id]?.trim()) : q.options.some((o) => o.selected)) ? 'bg-emerald-100 text-emerald-800' : 'bg-ink-100 text-ink-600']"
                        @click="current = i"
                    >
                        {{ i + 1 }}
                    </button>
                </div>
                <AppButton variant="outline" :disabled="current >= questions.length - 1" @click="current++">{{ $t('common.next') }}</AppButton>
            </div>

            <div class="flex justify-end pt-2">
                <AppButton variant="success" :loading="submitting" :disabled="submittingBusy || blocked" @click="confirmOpen = true">{{ $t('examTake.submitExam') }}</AppButton>
            </div>
        </template>

        <ConfirmDialog
            :open="confirmOpen"
            :title="$t('examTake.confirmTitle')"
            :message="confirmMessage"
            :confirm-text="$t('examTake.submitNow')"
            :loading="submitting"
            @close="confirmOpen = false"
            @confirm="submit"
        />
    </div>
</template>
