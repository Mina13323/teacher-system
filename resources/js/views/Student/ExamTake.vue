<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAsync } from '@/composables/useAsync';
import { student } from '@/api';
import { useToast } from '@/composables/toast';
import { useNotificationsStore } from '@/stores/notifications';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const notifications = useNotificationsStore();

const attempt = ref(null);
const result = ref(null);
const current = ref(0);
const submitting = ref(false);
const submittingBusy = ref(false);
const confirmOpen = ref(false);
const timeLeft = ref(0);
let timer = null;

const { loading, error, run: load } = useAsync(async () => {
    const a = await student.attempt(route.params.id);
    attempt.value = normalizeAttempt(a);
    startTimer();
});

// The attempt payload's `questions` and each question's `options` are nested
// resource collections (`{ data: [...] }`), so normalise to plain arrays.
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

const questions = computed(() => normalizeAttempt(attempt.value)?.questions || []);
const currentQuestion = computed(() => questions.value[current.value]);
const answeredCount = computed(() => questions.value.filter((q) => q.options.some((o) => o.selected)).length);

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

async function answer(optionId) {
    const q = currentQuestion.value;
    if (attempt.value?.status !== 'in_progress') return;
    try {
        const updated = await student.answer(attempt.value.id, { question_id: q.id, option_id: optionId });
        attempt.value = normalizeAttempt(updated);
    } catch (e) {
        toast.error(e.message);
    }
}

async function submit() {
    submitting.value = true;
    confirmOpen.value = false;
    try {
        const res = await student.submit(attempt.value.id);
        result.value = res;
        notifications.refreshUnread();
    } catch (e) {
        toast.error(e.message);
    } finally {
        submitting.value = false;
    }
}

async function onTimeUp() {
    submittingBusy.value = true;
    try {
        const res = await student.submit(attempt.value.id);
        result.value = res;
        toast.info('Time is up. Your attempt was submitted.');
    } catch (e) {
        toast.error('Time expired: ' + e.message);
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
            <div class="rounded-xl border border-ink-100 bg-white p-8 text-center shadow-sm">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full" :class="result?.passed === false || result?.status === 'expired' ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600'">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <h1 class="mt-4 text-2xl font-bold text-ink-900">Exam {{ result ? 'submitted' : (attempt.status === 'expired' ? 'expired' : 'completed') }}</h1>
                <p v-if="result?.percentage !== null && result?.percentage !== undefined" class="mt-2 text-ink-600">
                    You scored <span class="font-bold text-ink-900">{{ result.percentage }}%</span>
                </p>
                <p v-else-if="attempt?.percentage !== null && attempt?.percentage !== undefined" class="mt-2 text-ink-600">
                    You scored <span class="font-bold text-ink-900">{{ attempt.percentage }}%</span>
                </p>
                <AppBadge v-if="result?.passed !== null && result?.passed !== undefined" :tone="result.passed ? 'success' : 'danger'" class="mt-3">
                    {{ result.passed ? 'Passed' : 'Not passed' }}
                </AppBadge>
                <div class="mt-6"><AppButton @click="finish">Back to exams</AppButton></div>
            </div>
        </div>

        <!-- Active attempt -->
        <template v-else-if="attempt">
            <div class="flex items-center justify-between rounded-xl border border-ink-100 bg-white px-5 py-4 shadow-sm">
                <div>
                    <h1 class="text-lg font-semibold text-ink-900">{{ attempt.exam_title }}</h1>
                    <p class="text-xs text-ink-400">Attempt {{ attempt.attempt_number }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <AppBadge :tone="timeLeft < 60000 ? 'danger' : 'primary'">⏱ {{ fmt(timeLeft) }}</AppBadge>
                    <span class="text-xs text-ink-400">Answered {{ answeredCount }}/{{ questions.length }}</span>
                </div>
            </div>

            <!-- Question -->
            <div class="rounded-xl border border-ink-100 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between text-sm text-ink-500">
                    <span>Question {{ current + 1 }} of {{ questions.length }}</span>
                    <span>{{ currentQuestion?.points }} pts</span>
                </div>
                <p class="mt-3 text-lg font-medium text-ink-900">{{ currentQuestion?.question_text }}</p>
                <div class="mt-5 space-y-2">
                    <button
                        v-for="opt in currentQuestion?.options"
                        :key="opt.id"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg border px-4 py-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400"
                        :class="opt.selected ? 'border-terracotta-500 bg-terracotta-50' : 'border-ink-200 hover:border-ink-300 hover:bg-ink-50'"
                        @click="answer(opt.id)"
                    >
                        <span class="flex h-5 w-5 items-center justify-center rounded-full border" :class="opt.selected ? 'border-terracotta-500 bg-terracotta-500 text-white' : 'border-ink-300'">
                            <svg v-if="opt.selected" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <span class="text-ink-800">{{ opt.option_text }}</span>
                    </button>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex items-center justify-between gap-3">
                <AppButton variant="outline" :disabled="current === 0" @click="current--">Previous</AppButton>
                <div class="flex flex-wrap justify-center gap-1.5">
                    <button
                        v-for="(q, i) in questions"
                        :key="q.id"
                        type="button"
                        class="h-9 w-9 rounded-lg text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400"
                        :class="[i === current ? 'bg-terracotta-600 text-white' : q.options.some((o) => o.selected) ? 'bg-emerald-100 text-emerald-800' : 'bg-ink-100 text-ink-600']"
                        @click="current = i"
                    >
                        {{ i + 1 }}
                    </button>
                </div>
                <AppButton variant="outline" :disabled="current >= questions.length - 1" @click="current++">Next</AppButton>
            </div>

            <div class="flex justify-end">
                <AppButton variant="success" :loading="submitting" :disabled="submittingBusy" @click="confirmOpen = true">Submit exam</AppButton>
            </div>
        </template>

        <ConfirmDialog
            :open="confirmOpen"
            title="Submit exam?"
            :message="`You've answered ${answeredCount} of ${questions.length} questions. Once submitted, you cannot change your answers.`"
            confirm-text="Submit now"
            :loading="submitting"
            @close="confirmOpen = false"
            @confirm="submit"
        />
    </div>
</template>
