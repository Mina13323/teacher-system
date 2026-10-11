<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher } from '@/api';
import { useToast } from '@/composables/toast';
import { createGradingSession } from '@/utils/gradingSession';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppModal from '@/components/ui/AppModal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

/**
 * Essay-grading workspace: one essay answer at a time, from a server-paginated
 * queue of handed-in attempts. Saves are compact and conflict-aware (see
 * utils/gradingSession.js); nothing here reloads the whole attempt.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const examId = route.params.id;

const session = createGradingSession({ api: teacher, examId, wrap: reactive });
const s = session.state;

// Deep links: ?attempt_id=, ?student_id=, ?question_id=, ?status=, ?mode=student
const q = route.query;
if (q.mode === 'student' || q.attempt_id || q.student_id) s.filters.mode = 'student';
if (q.attempt_id) s.filters.attempt_id = Number(q.attempt_id);
if (q.student_id) s.filters.student_id = Number(q.student_id);
if (q.question_id) s.filters.question_id = Number(q.question_id);
if (['ungraded', 'graded', 'all'].includes(q.status)) s.filters.status = q.status;
else if (q.attempt_id || q.student_id) s.filters.status = 'all';

const item = computed(() => session.current());
const draft = computed(() => session.draftFor(item.value));
const listOpen = ref(false);
const pointsInput = ref(null);

const totals = computed(() => {
    const sum = s.summary;
    if (!sum) return null;
    return { graded: sum.graded, ungraded: sum.ungraded, total: sum.graded + sum.ungraded, ready: sum.attempts?.ready_to_publish ?? 0 };
});
const progressPct = computed(() => (totals.value?.total ? Math.round((totals.value.graded / totals.value.total) * 100) : 0));
const questionMeta = computed(() => s.summary?.questions?.find((x) => x.question_id === item.value?.question_id) ?? null);
const questionOptions = computed(() => (s.summary?.questions ?? []).map((x, i) => ({
    value: x.question_id,
    label: t('grading.questionOption', { n: i + 1, left: x.ungraded }),
})));

const validation = computed(() => (item.value ? session.validation(item.value) : null));
const pointsError = computed(() => {
    const v = validation.value;
    if (!draft.value || draft.value.points === '' || !v || v.ok) return '';
    return t('exams.awardedPointsRange', { max: item.value.max_points });
});

const stateLabel = computed(() => {
    const d = draft.value;
    const it = item.value;
    if (!d || !it) return '';
    if (d.state === 'saving') return t('grading.stateSaving');
    if (d.state === 'conflict') return t('grading.stateConflict');
    if (d.state === 'failed') return t('grading.stateFailed');
    if (d.dirty) return t('grading.stateUnsaved');
    if (d.state === 'saved') return t('grading.stateSaved');
    return it.points_earned === null ? t('grading.stateUngraded') : t('grading.stateGraded', { n: it.points_earned });
});
const stateTone = computed(() => {
    const d = draft.value;
    if (!d) return 'neutral';
    if (d.state === 'conflict' || d.state === 'failed') return 'danger';
    if (d.dirty || d.state === 'saving') return 'warning';
    if (d.state === 'saved' || item.value?.points_earned !== null) return 'success';
    return 'neutral';
});
const failureMessage = computed(() => {
    const e = draft.value?.error;
    if (!e) return '';
    if (e.kind === 'invalid') return e.reason === 'empty' ? t('exams.awardedPointsRequired') : t('exams.awardedPointsRange', { max: item.value?.max_points });
    if (e.kind === 'network') return t('grading.errorNetwork');
    if (e.kind === 'rejected') return e.message;
    return '';
});

function rowState(row) {
    const d = s.drafts[`${row.attempt_id}:${row.question_id}`];
    if (d?.state === 'failed' || d?.state === 'conflict') return 'danger';
    if (d?.dirty) return 'warning';
    return row.points_earned === null ? 'neutral' : 'success';
}

let searchTimer = null;
function onSearch(value) {
    s.filters.search = value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => session.load({ includeSummary: false }), 350);
}

async function changeFilter(name, value) {
    await session.setFilter(name, value);
}

async function act(fn) {
    await fn();
    listOpen.value = false;
}

async function saveAndNext() {
    const result = await session.saveAndNext();
    if (result === 'saved' || result === 'unchanged') pointsInput.value?.focus();
}

function setQuick(value) {
    session.setPoints(String(value));
}

// ---- Publish all ------------------------------------------------------------
const publishOpen = ref(false);
const publishBusy = ref(false);
async function publishAll() {
    publishBusy.value = true;
    try {
        const totalsDone = await session.publishAll();
        toast.success(t('grading.publishDone', { published: totalsDone.published, blocked: totalsDone.blocked_ungraded, failed: totalsDone.failed }));
        publishOpen.value = false;
        await session.load({ includeSummary: true, index: s.index });
    } catch (e) {
        toast.error(e.message);
    } finally {
        publishBusy.value = false;
    }
}

// ---- Keyboard: never steals keystrokes from fields --------------------------
function onKey(e) {
    if (publishOpen.value || leaveTarget.value) return;
    const target = e.target;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        saveAndNext();
        return;
    }
    if (typing || e.altKey || e.ctrlKey || e.metaKey) return;
    if (e.key === 'j') { e.preventDefault(); session.next(); }
    else if (e.key === 'k') { e.preventDefault(); session.previous(); }
    else if (e.key === 'n') { e.preventDefault(); session.nextUngraded(); }
}

// ---- Unsaved-change protection ---------------------------------------------
const leaveTarget = ref(null);
let allowLeave = false;
onBeforeRouteLeave((to) => {
    if (allowLeave || !session.hasUnsaved()) return true;
    leaveTarget.value = to;
    return false;
});
async function confirmLeave() {
    const to = leaveTarget.value;
    leaveTarget.value = null;
    allowLeave = true;
    await router.push(to.fullPath);
}
function onBeforeUnload(e) {
    if (!session.hasUnsaved()) return;
    e.preventDefault();
    e.returnValue = '';
}

watch(() => s.index, () => { listOpen.value = false; });

onMounted(() => {
    window.addEventListener('keydown', onKey);
    window.addEventListener('beforeunload', onBeforeUnload);
    session.load();
});
onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('beforeunload', onBeforeUnload);
});
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-4">
        <!-- Header: compact and sticky under the portal header -->
        <header class="sticky top-[calc(4rem+env(safe-area-inset-top,0px))] z-10 -mx-3 border-b border-ink-100 bg-parchment-50/95 px-3 py-3 backdrop-blur sm:-mx-4 sm:px-4 lg:static lg:mx-0 lg:border-0 lg:bg-transparent lg:px-0 lg:py-0">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="min-w-0">
                    <router-link :to="`/teacher/exams/${examId}`" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('grading.backToExam') }}</router-link>
                    <h1 class="truncate text-lg font-bold text-ink-900 sm:text-2xl" dir="auto">{{ $t('grading.title') }} · {{ s.exam?.title || '' }}</h1>
                </div>
                <AppButton size="sm" variant="success" :disabled="!totals" @click="publishOpen = true">{{ $t('grading.publishAll') }}</AppButton>
            </div>
            <div v-if="totals" class="mt-2">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-600 sm:text-sm">
                    <span class="font-semibold text-ink-800">{{ $t('grading.progress', { graded: totals.graded, total: totals.total }) }}</span>
                    <span class="text-amber-700">{{ $t('grading.ungradedCount', { n: totals.ungraded }) }}</span>
                    <span class="text-emerald-700">{{ $t('grading.readyToPublish', { n: totals.ready }) }}</span>
                </div>
                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-ink-100" role="progressbar" :aria-valuenow="progressPct" aria-valuemin="0" aria-valuemax="100" :aria-label="$t('grading.progress', { graded: totals.graded, total: totals.total })">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${progressPct}%` }" />
                </div>
            </div>
        </header>

        <!-- Filters -->
        <details class="group rounded-xl border border-ink-100 bg-white shadow-sm" open>
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between px-4 py-2 text-sm font-semibold text-ink-800">
                {{ $t('grading.filters') }}
                <span class="text-ink-400 transition group-open:rotate-180" aria-hidden="true">▾</span>
            </summary>
            <div class="grid gap-3 border-t border-ink-100 p-4 sm:grid-cols-2 lg:grid-cols-5">
                <label class="block text-sm" for="grading-mode">
                    <span class="mb-1 block font-medium text-ink-700">{{ $t('grading.mode') }}</span>
                    <select id="grading-mode" class="min-h-11 w-full rounded-lg border border-ink-200 bg-white px-3 text-sm" :value="s.filters.mode" @change="changeFilter('mode', $event.target.value)">
                        <option value="question">{{ $t('grading.modeQuestion') }}</option>
                        <option value="student">{{ $t('grading.modeStudent') }}</option>
                    </select>
                </label>
                <label v-if="s.filters.mode === 'question'" class="block text-sm" for="grading-question">
                    <span class="mb-1 block font-medium text-ink-700">{{ $t('grading.question') }}</span>
                    <select id="grading-question" class="min-h-11 w-full rounded-lg border border-ink-200 bg-white px-3 text-sm" :value="s.filters.question_id ?? ''" @change="changeFilter('question_id', Number($event.target.value))">
                        <option v-for="opt in questionOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                </label>
                <label class="block text-sm" for="grading-status">
                    <span class="mb-1 block font-medium text-ink-700">{{ $t('grading.status') }}</span>
                    <select id="grading-status" class="min-h-11 w-full rounded-lg border border-ink-200 bg-white px-3 text-sm" :value="s.filters.status" @change="changeFilter('status', $event.target.value)">
                        <option value="ungraded">{{ $t('grading.statusUngraded') }}</option>
                        <option value="graded">{{ $t('grading.statusGraded') }}</option>
                        <option value="all">{{ $t('grading.statusAll') }}</option>
                    </select>
                </label>
                <label class="block text-sm" for="grading-publication">
                    <span class="mb-1 block font-medium text-ink-700">{{ $t('grading.publication') }}</span>
                    <select id="grading-publication" class="min-h-11 w-full rounded-lg border border-ink-200 bg-white px-3 text-sm" :value="s.filters.publication_status" @change="changeFilter('publication_status', $event.target.value)">
                        <option value="all">{{ $t('grading.pubAll') }}</option>
                        <option value="unpublished">{{ $t('grading.pubUnpublished') }}</option>
                        <option value="published">{{ $t('grading.pubPublished') }}</option>
                    </select>
                </label>
                <label class="block text-sm" for="grading-search">
                    <span class="mb-1 block font-medium text-ink-700">{{ $t('grading.search') }}</span>
                    <input id="grading-search" type="search" class="min-h-11 w-full rounded-lg border border-ink-200 px-3 text-sm" :value="s.filters.search" dir="auto" @input="onSearch($event.target.value)" />
                </label>
            </div>
        </details>

        <LoadingSpinner v-if="s.loading && !s.items.length" />
        <div v-else-if="s.loadError && !s.items.length" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
            <p>{{ $t('grading.loadError') }} {{ s.loadError.message }}</p>
            <AppButton class="mt-2" size="sm" variant="outline" @click="session.load()">{{ $t('grading.retry') }}</AppButton>
        </div>
        <EmptyState v-else-if="!s.items.length" icon="check" :title="$t('grading.emptyTitle')" :message="$t('grading.emptyMessage')" />

        <div v-else class="grid gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <!-- Answers on this page: sidebar on desktop, toggle on smaller screens -->
            <nav class="lg:sticky lg:top-20 lg:self-start" :aria-label="$t('grading.answersOnPage', { n: s.items.length })">
                <button type="button" class="flex min-h-11 w-full items-center justify-between rounded-xl border border-ink-100 bg-white px-4 text-sm font-semibold text-ink-800 shadow-sm lg:hidden" :aria-expanded="listOpen" aria-controls="grading-list" @click="listOpen = !listOpen">
                    {{ $t('grading.answersOnPage', { n: s.items.length }) }}
                    <span aria-hidden="true">{{ listOpen ? '▴' : '▾' }}</span>
                </button>
                <ol id="grading-list" class="mt-2 space-y-1 rounded-xl border border-ink-100 bg-white p-2 shadow-sm lg:mt-0 lg:block" :class="listOpen ? 'block' : 'hidden'">
                    <li v-for="(row, i) in s.items" :key="`${row.attempt_id}:${row.question_id}`">
                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center gap-2 rounded-lg px-3 py-2 text-start text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400"
                            :class="i === s.index ? 'bg-terracotta-50 font-semibold text-terracotta-900' : 'text-ink-700 hover:bg-ink-50'"
                            :aria-current="i === s.index ? 'true' : undefined"
                            @click="act(() => session.select(i))"
                        >
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="{ success: 'bg-emerald-500', warning: 'bg-amber-500', danger: 'bg-rose-500', neutral: 'bg-ink-300' }[rowState(row)]" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate" dir="auto">{{ row.student.name }}</span>
                            <span class="shrink-0 text-xs text-ink-400">{{ s.filters.mode === 'student' ? `Q${row.position}` : (row.points_earned ?? '—') }}</span>
                        </button>
                    </li>
                </ol>
                <p v-if="s.meta && s.meta.last_page > 1" class="mt-2 px-1 text-xs text-ink-500">{{ s.meta.current_page }} / {{ s.meta.last_page }}</p>
            </nav>

            <!-- Answer panel -->
            <section v-if="item" class="min-w-0 space-y-4" aria-labelledby="grading-student">
                <div class="rounded-xl border border-ink-100 bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p id="grading-student" class="break-words text-base font-semibold text-ink-900" dir="auto">{{ item.student.name }}</p>
                            <p class="text-xs text-ink-500">
                                <bdi v-if="item.student.student_code" dir="ltr" class="font-mono">{{ item.student.student_code }}</bdi>
                                · {{ $t('grading.attemptN', { n: item.attempt_number }) }}
                                <span v-if="item.grades_published" class="font-medium text-emerald-700"> · {{ $t('grading.pubPublished') }}</span>
                            </p>
                        </div>
                        <AppBadge :tone="stateTone"><span aria-live="polite" role="status">{{ stateLabel }}</span></AppBadge>
                    </div>

                    <div class="mt-4 border-t border-ink-100 pt-4">
                        <p class="text-xs font-semibold text-ink-500">{{ $t('grading.maxPoints', { n: item.max_points }) }}</p>
                        <p class="mt-1 whitespace-pre-wrap break-words text-sm font-medium text-ink-900" dir="auto">{{ item.question_text }}</p>
                        <details v-if="questionMeta?.reference_answer" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            <summary class="min-h-9 cursor-pointer font-semibold">{{ $t('grading.referenceAnswer') }}</summary>
                            <p class="mt-1 whitespace-pre-wrap break-words" dir="auto">{{ questionMeta.reference_answer }}</p>
                        </details>
                    </div>

                    <div class="mt-4">
                        <p class="mb-1 text-xs font-semibold text-ink-500">{{ $t('grading.studentAnswer') }}</p>
                        <div v-if="item.has_answer" class="max-w-none whitespace-pre-wrap break-words rounded-lg bg-ink-50 p-3 text-base leading-relaxed text-ink-900" dir="auto">{{ item.answer_text }}</div>
                        <p v-else class="rounded-lg border border-dashed border-ink-300 p-3 text-sm italic text-ink-500">{{ $t('grading.blankAnswer') }}</p>
                    </div>
                </div>

                <!-- Grading controls: sticky above the mobile bottom bar -->
                <div class="sticky bottom-[calc(4.75rem+env(safe-area-inset-bottom,0px))] z-10 rounded-xl border border-ink-200 bg-white p-4 shadow-lg lg:bottom-4">
                    <div v-if="draft?.state === 'conflict' && draft.conflict" class="mb-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900" role="alert">
                        <p class="font-semibold">{{ $t('grading.conflictTitle') }}</p>
                        <p class="mt-1">{{ $t('grading.conflictBody', { points: draft.conflict.points_earned ?? '—', mine: draft.points || '—' }) }}</p>
                        <p v-if="draft.conflict.feedback" class="mt-1 whitespace-pre-wrap break-words" dir="auto">{{ $t('grading.conflictFeedback', { feedback: draft.conflict.feedback }) }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <AppButton size="sm" variant="outline" @click="session.acceptServerVersion()">{{ $t('grading.useTheirs') }}</AppButton>
                            <AppButton size="sm" variant="primary" @click="session.keepMineAfterConflict()">{{ $t('grading.keepMine') }}</AppButton>
                        </div>
                    </div>
                    <p v-else-if="failureMessage" class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">{{ failureMessage }}</p>

                    <div class="grid gap-3 sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)]">
                        <div>
                            <label :for="`grade-points-${item.attempt_id}-${item.question_id}`" class="mb-1 block text-sm font-medium text-ink-800">{{ $t('grading.points', { max: item.max_points }) }}</label>
                            <input
                                :id="`grade-points-${item.attempt_id}-${item.question_id}`"
                                ref="pointsInput"
                                type="number"
                                inputmode="numeric"
                                min="0"
                                step="1"
                                :max="item.max_points"
                                class="min-h-11 w-full rounded-lg border px-3 text-base"
                                :class="pointsError ? 'border-rose-300' : 'border-ink-200'"
                                :value="draft?.points ?? ''"
                                :aria-invalid="Boolean(pointsError)"
                                :aria-describedby="pointsError ? `grade-points-error-${item.attempt_id}-${item.question_id}` : undefined"
                                @input="session.setPoints($event.target.value)"
                            />
                            <p v-if="pointsError" :id="`grade-points-error-${item.attempt_id}-${item.question_id}`" class="mt-1 text-xs text-rose-600">{{ pointsError }}</p>
                            <div class="mt-2 flex gap-2">
                                <button type="button" class="min-h-11 flex-1 rounded-lg border border-ink-200 px-3 text-sm font-medium hover:bg-ink-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400" @click="setQuick(0)">{{ $t('grading.pointsZero') }}</button>
                                <button type="button" class="min-h-11 flex-1 rounded-lg border border-ink-200 px-3 text-sm font-medium hover:bg-ink-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-400" @click="setQuick(item.max_points)">{{ $t('grading.pointsMax', { n: item.max_points }) }}</button>
                            </div>
                        </div>
                        <div>
                            <label :for="`grade-feedback-${item.attempt_id}-${item.question_id}`" class="mb-1 block text-sm font-medium text-ink-800">{{ $t('grading.feedback') }}</label>
                            <textarea
                                :id="`grade-feedback-${item.attempt_id}-${item.question_id}`"
                                rows="3"
                                maxlength="2000"
                                class="w-full rounded-lg border border-ink-200 px-3 py-2 text-base"
                                dir="auto"
                                :value="draft?.feedback ?? ''"
                                @input="session.setFeedback($event.target.value)"
                            />
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center sm:justify-between">
                        <div class="col-span-2 grid grid-cols-3 gap-2 sm:flex">
                            <AppButton size="sm" variant="outline" class="min-h-11" :disabled="s.loading" @click="session.previous()">{{ $t('grading.previous') }}</AppButton>
                            <AppButton size="sm" variant="outline" class="min-h-11" :disabled="s.loading" @click="session.next()">{{ $t('grading.next') }}</AppButton>
                            <AppButton size="sm" variant="ghost" class="min-h-11" :disabled="s.loading" @click="session.nextUngraded()">{{ $t('grading.nextUngraded') }}</AppButton>
                        </div>
                        <AppButton size="sm" variant="outline" class="min-h-11" :loading="draft?.state === 'saving'" :disabled="draft?.state === 'conflict' || Boolean(pointsError)" @click="session.save()">{{ $t('grading.save') }}</AppButton>
                        <AppButton size="sm" variant="primary" class="min-h-11" :loading="draft?.state === 'saving'" :disabled="draft?.state === 'conflict' || Boolean(pointsError)" @click="saveAndNext">{{ $t('grading.saveNext') }}</AppButton>
                    </div>
                    <p class="mt-2 hidden text-xs text-ink-400 lg:block">{{ $t('grading.keyboardHint') }}</p>
                </div>
            </section>
        </div>

        <!-- Publish all fully graded attempts -->
        <AppModal :open="publishOpen" :title="$t('grading.publishTitle')" size="sm" @close="publishOpen = false">
            <ul class="space-y-1 text-sm text-ink-700">
                <li class="font-semibold text-emerald-800">{{ $t('grading.publishReady', { n: s.summary?.attempts?.ready_to_publish ?? 0 }) }}</li>
                <li>{{ $t('grading.publishAwaiting', { n: s.summary?.attempts?.awaiting_grading ?? 0 }) }}</li>
                <li>{{ $t('grading.publishAlready', { n: s.summary?.attempts?.published ?? 0 }) }}</li>
            </ul>
            <p class="mt-3 text-xs text-ink-500">{{ $t('grading.publishExplain') }}</p>
            <template #footer>
                <AppButton variant="outline" :disabled="publishBusy" @click="publishOpen = false">{{ $t('common.cancel') }}</AppButton>
                <AppButton
                    variant="success"
                    :loading="publishBusy"
                    :disabled="!(s.summary?.attempts?.ready_to_publish > 0)"
                    @click="publishAll"
                >
                    {{ (s.summary?.attempts?.ready_to_publish ?? 0) > 0 ? $t('grading.publishConfirm', { n: s.summary.attempts.ready_to_publish }) : $t('grading.publishNone') }}
                </AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :open="Boolean(leaveTarget)"
            :title="$t('grading.leaveTitle')"
            :message="$t('grading.leaveMessage')"
            :confirm-text="$t('grading.leaveConfirm')"
            @close="leaveTarget = null"
            @confirm="confirmLeave"
        />
    </div>
</template>
