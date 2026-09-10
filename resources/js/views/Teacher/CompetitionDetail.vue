<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Icon from '@/components/ui/Icon.vue';

const { t } = useI18n();
const route = useRoute();
const toast = useToast();
const id = route.params.id;

const { loading, error, data, run } = useAsync(() => teacher.competition(id));

const participants = ref([]);
const participantsMeta = ref(null);
const leaderboard = ref([]);
const leaderboardMeta = ref(null);
const recalcBusy = ref(false);

const participantPage = ref(1);
const leaderboardPage = ref(1);

async function loadParticipants(p = 1) {
    participantPage.value = p;
    const res = toList(await teacher.competitionParticipants(id, { per_page: 15, page: p }));
    participants.value = res.items;
    participantsMeta.value = res.meta;
}
async function loadLeaderboard(p = 1) {
    leaderboardPage.value = p;
    const res = toList(await teacher.competitionLeaderboard(id, { per_page: 15, page: p }));
    leaderboard.value = res.items;
    leaderboardMeta.value = res.meta;
}

async function recalc() {
    recalcBusy.value = true;
    try {
        await teacher.recalculateLeaderboard(id);
        toast.success(t('competitions.recalculated'));
        loadLeaderboard(1);
    } catch (e) {
        toast.error(e.message);
    } finally {
        recalcBusy.value = false;
    }
}
async function disqualify(p) {
    try {
        await teacher.disqualifyParticipant(id, p.id);
        toast.success(t('competitions.disqualifiedToast'));
        loadParticipants(1);
        loadLeaderboard(1);
    } catch (e) {
        toast.error(e.message);
    }
}

onMounted(async () => {
    await run();
    await Promise.all([loadParticipants(1), loadLeaderboard(1)]);
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <router-link to="/teacher/competitions" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.competitions') }}</router-link>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-ink-900" dir="auto">{{ data?.title || $t('competitions.contest') }}</h1>
                <AppBadge :tone="data?.status === 'active' ? 'success' : data?.status === 'published' ? 'primary' : 'neutral'">{{ data?.status ? $t(`status.${data.status}`, data.status) : '' }}</AppBadge>
                <router-link :to="`/teacher/competitions/${id}/edit`"><AppButton variant="outline" size="sm">{{ $t('common.edit') }}</AppButton></router-link>
            </div>
            <p class="mt-1 text-ink-500" dir="auto">{{ data?.description }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <AppButton variant="outline" size="sm" :loading="recalcBusy" @click="recalc">{{ $t('competitions.recalculate') }}</AppButton>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>

        <template v-else>
            <AppCard :title="$t('competitions.participants')">
                <EmptyState v-if="!participants.length" icon="users" :title="$t('competitions.noParticipantsTitle')" :message="$t('competitions.noParticipantsMessage')" />
                <div v-else class="space-y-2">
                    <div v-for="p in participants" :key="p.id" class="flex items-center gap-3 rounded-lg border border-ink-100 px-4 py-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (p.student_display_name || 'U').slice(0, 1) }}</div>
                        <span class="min-w-0 flex-1 truncate font-medium text-ink-800" dir="auto">{{ p.student_display_name }}</span>
                        <AppBadge :tone="p.status === 'disqualified' ? 'danger' : p.status === 'qualified' ? 'success' : 'neutral'">{{ $t(`status.${p.status}`, p.status) }}</AppBadge>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="disqualify(p)">{{ $t('competitions.disqualify') }}</button>
                    </div>
                    <Pagination v-if="participantsMeta" :meta="participantsMeta" @change="loadParticipants" />
                </div>
            </AppCard>

            <AppCard :title="$t('competitions.leaderboard')">
                <EmptyState v-if="!leaderboard.length" icon="trophy" :title="$t('competitions.noRankingsTitle')" :message="$t('competitions.noRankingsMessage')" />
                <div v-else class="space-y-2">
                    <div v-for="row in leaderboard" :key="row.rank + row.student_display_name" class="flex items-center gap-3 rounded-lg px-4 py-2.5" :class="row.rank <= 3 ? 'bg-parchment-100' : 'bg-ink-50/50'">
                        <span class="w-8 text-center font-bold text-ink-500">{{ row.rank }}</span>
                        <Icon v-if="row.rank === 1" name="trophy" :size="18" class="text-amber-500" />
                        <span class="flex-1 font-medium text-ink-800" dir="auto">{{ row.student_display_name }}</span>
                        <span class="text-sm text-ink-600">{{ row.score }}</span>
                        <span class="text-xs text-ink-400">{{ row.percentage ?? '—' }}%</span>
                        <AppBadge v-if="row.qualified !== undefined" :tone="row.qualified ? 'success' : 'neutral'">{{ row.qualified ? $t('status.qualified') : '—' }}</AppBadge>
                    </div>
                    <Pagination v-if="leaderboardMeta" :meta="leaderboardMeta" @change="loadLeaderboard" />
                </div>
            </AppCard>
        </template>
    </div>
</template>
