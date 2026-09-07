<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Icon from '@/components/ui/Icon.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const loading = ref(true);
const detail = ref(null); // ExamAttemptDetailResource
const integrity = ref(null); // ExamAttemptIntegrityResource
const events = ref([]);
const error = ref('');

const review = ref({ decision: '', note: '' });
const reviewErrors = ref({});
const reviewBusy = ref(false);

const decisionOptions = [
    { value: 'CLEARED', label: 'Cleared' },
    { value: 'FLAGGED', label: 'Flagged' },
];

function severityTone(s) {
    return { high: 'danger', medium: 'warning', low: 'info' }[s] || 'neutral';
}
function statusTone(s) {
    return { flagged: 'danger', monitoring: 'warning', cleared: 'success', reviewed: 'info' }[s] || 'neutral';
}
function dateOf(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const [d, i] = await Promise.all([
            teacher.attempt(route.params.id),
            teacher.attemptIntegrity(route.params.id),
        ]);
        detail.value = d;
        integrity.value = i;
        // `events` / `reviews` are nested resource collections (`{ data: [...] }`).
        events.value = toList(i.events).items;
        if (i.reviews) {
            integrity.value = { ...i, reviews: toList(i.reviews).items };
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function submitReview() {
    reviewBusy.value = true;
    reviewErrors.value = {};
    try {
        await teacher.reviewAttempt(route.params.id, {
            decision: review.value.decision,
            note: review.value.note || null,
        });
        toast.success('Review recorded.');
        review.value = { decision: '', note: '' };
        await load();
    } catch (e) {
        reviewErrors.value = fieldErrors(e);
        toast.error(e.isValidation ? 'Please select a decision.' : e.message);
    } finally {
        reviewBusy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div>
            <button class="text-sm font-medium text-terracotta-600 hover:underline" @click="router.push('/teacher/integrity')">← Back to integrity</button>
            <h1 class="mt-2 text-2xl font-bold text-ink-900">Attempt #{{ detail?.id || route.params.id }}</h1>
            <p class="text-ink-500">{{ detail?.exam_title }} · {{ detail?.student?.name }}</p>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Score" :value="detail?.percentage !== null && detail?.percentage !== undefined ? detail.percentage + '%' : '—'" icon="clipboard" tone="terracotta" />
                <StatCard label="Pass" :value="detail?.passed === true ? 'Passed' : detail?.passed === false ? 'Not passed' : '—'" icon="check" :tone="detail?.passed ? 'emerald' : 'danger'" />
                <StatCard label="Integrity status" :value="detail?.integrity_status || '—'" icon="shield" :tone="statusTone(detail?.integrity_status) === 'danger' ? 'danger' : statusTone(detail?.integrity_status) === 'warning' ? 'amber' : 'ink'" />
                <StatCard label="Risk score" :value="detail?.risk_score ?? '—'" icon="shield" tone="amber" />
            </div>

            <AppCard title="Frozen integrity settings">
                <div v-if="integrity?.frozen_settings" class="flex flex-wrap gap-3 text-sm">
                    <AppBadge :tone="integrity.frozen_settings.fullscreen_required ? 'success' : 'neutral'">Fullscreen required</AppBadge>
                    <AppBadge :tone="integrity.frozen_settings.prevent_copy ? 'success' : 'neutral'">Prevent copy</AppBadge>
                    <AppBadge :tone="integrity.frozen_settings.prevent_paste ? 'success' : 'neutral'">Prevent paste</AppBadge>
                    <AppBadge :tone="integrity.frozen_settings.prevent_context_menu ? 'success' : 'neutral'">Prevent context menu</AppBadge>
                    <AppBadge :tone="integrity.frozen_settings.detect_tab_switch ? 'success' : 'neutral'">Detect tab switch</AppBadge>
                    <AppBadge :tone="integrity.frozen_settings.detect_window_blur ? 'success' : 'neutral'">Detect window blur</AppBadge>
                    <AppBadge :tone="integrity.frozen_settings.detect_keyboard_shortcuts ? 'success' : 'neutral'">Detect shortcuts</AppBadge>
                </div>
                <p v-else class="text-sm text-ink-400">No frozen settings recorded for this attempt.</p>
            </AppCard>

            <AppCard title="Recorded integrity events">
                <div v-if="integrity?.multiple_suspicious_events" class="mb-3 flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
                    <Icon name="shield" :size="16" /> Multiple suspicious events detected on this attempt.
                </div>
                <EmptyState v-if="!events.length" icon="shield" title="No integrity events" message="No risk-bearing events were recorded for this attempt." />
                <div v-else class="divide-y divide-ink-100">
                    <div v-for="e in events" :key="e.id" class="flex items-start gap-3 py-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600"><Icon name="shield" :size="18" /></div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-ink-800">{{ e.event_type }}</span>
                                <AppBadge :tone="severityTone(e.severity)">{{ e.severity }}</AppBadge>
                            </div>
                            <p class="text-xs text-ink-400">{{ dateOf(e.occurred_at) }} · +{{ e.risk_points }} risk points</p>
                        </div>
                    </div>
                </div>
            </AppCard>

            <AppCard title="Review trail">
                <EmptyState v-if="!integrity?.reviews?.length" icon="clipboard" title="No reviews yet" message="Record a decision to build the audit trail." />
                <div v-else class="space-y-3">
                    <div v-for="r in integrity.reviews" :key="r.id" class="rounded-lg bg-ink-50 px-4 py-3 text-sm">
                        <div class="flex items-center gap-2">
                            <AppBadge :tone="r.decision === 'CLEARED' ? 'success' : 'danger'">{{ r.decision }}</AppBadge>
                            <span class="text-ink-400">{{ r.reviewer?.name }} · {{ dateOf(r.reviewed_at) }}</span>
                        </div>
                        <p v-if="r.note" class="mt-1 text-ink-600">{{ r.note }}</p>
                    </div>
                </div>
            </AppCard>

            <AppCard title="Record a decision">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppSelect v-model="review.decision" label="Decision" :options="decisionOptions" id="integrity-decision" :error="reviewErrors.decision" placeholder="Select a decision" />
                    <div class="sm:col-span-2"><AppTextarea v-model="review.note" label="Note" id="integrity-note" :error="reviewErrors.note" :rows="2" placeholder="Optional context for this decision" /></div>
                </div>
                <div class="mt-4 flex justify-end"><AppButton :loading="reviewBusy" :disabled="!review.decision" @click="submitReview">Save review</AppButton></div>
            </AppCard>
        </template>
    </div>
</template>
