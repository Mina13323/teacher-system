<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAsync } from '@/composables/useAsync';
import { student, toList } from '@/api';
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
const joining = ref(false);
const joined = ref(false);
const me = ref(null);
const leaderboard = ref(null);
const page = ref(1);

const { loading, error, data, run: loadDetail } = useAsync(async () => {
    const c = await student.competition(route.params.id);
    joined.value = c.is_joined;
    return c;
});

async function loadLeaderboard(p = 1) {
    page.value = p;
    const res = toList(await student.competitionLeaderboard(route.params.id, { per_page: 20, page: p }));
    leaderboard.value = res;
}

async function loadMe() {
    try {
        me.value = await student.competitionMe(route.params.id);
    } catch {
        me.value = null;
    }
}

async function join() {
    joining.value = true;
    try {
        await student.joinCompetition(route.params.id);
        joined.value = true;
        toast.success(t('competitions.joinedToast'));
        loadMe();
    } catch (e) {
        toast.error(e.message);
    } finally {
        joining.value = false;
    }
}

onMounted(async () => {
    await loadDetail();
    await Promise.all([loadLeaderboard(1), loadMe()]);
});
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>
        <template v-else>
            <div>
                <router-link to="/student/competitions" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('competitions.allCompetitions') }}</router-link>
                <h1 class="mt-2 text-2xl font-bold text-ink-900" dir="auto">{{ data.title }}</h1>
                <p class="mt-1 text-ink-600" dir="auto">{{ data.description }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <AppBadge :tone="data.status === 'active' ? 'success' : 'primary'">{{ $t(`status.${data.status}`, data.status) }}</AppBadge>
                    <AppBadge tone="neutral">{{ data.exam_title }}</AppBadge>
                    <AppBadge tone="neutral">{{ $t('competitions.participantsN', { n: data.participants_count }) }}</AppBadge>
                </div>
            </div>

            <AppCard v-if="!joined" :title="$t('competitions.joinTitle')">
                <p class="text-sm text-ink-600">{{ $t('competitions.joinHint') }}</p>
                <div class="mt-4"><AppButton :loading="joining" @click="join">{{ $t('competitions.join') }}</AppButton></div>
            </AppCard>

            <AppCard v-else :title="$t('competitions.yourPosition')">
                <div v-if="me" class="flex flex-wrap items-center gap-6">
                    <div><p class="text-sm text-ink-400">{{ $t('competitions.rank') }}</p><p class="text-2xl font-bold text-ink-900">{{ me.rank ? `#${me.rank}` : '—' }}</p></div>
                    <div><p class="text-sm text-ink-400">{{ $t('analytics.score') }}</p><p class="text-lg font-semibold text-ink-800">{{ me.score ?? '—' }}</p></div>
                    <div><p class="text-sm text-ink-400">{{ $t('competitions.percentage') }}</p><p class="text-lg font-semibold text-ink-800">{{ me.percentage ?? '—' }}%</p></div>
                    <div><p class="text-sm text-ink-400">{{ $t('competitions.participants') }}</p><p class="text-lg font-semibold text-ink-800">{{ me.total_participants }}</p></div>
                    <AppBadge v-if="me.qualified !== null && me.qualified !== undefined" :tone="me.qualified ? 'success' : 'neutral'">{{ me.qualified ? $t('status.qualified') : $t('status.disqualified') }}</AppBadge>
                </div>
                <p v-else class="text-sm text-ink-500">{{ $t('competitions.noResultYet') }}</p>
            </AppCard>

            <AppCard :title="$t('competitions.leaderboard')">
                <div v-if="leaderboard?.items?.length" class="space-y-2">
                    <div v-for="row in leaderboard.items" :key="row.rank + row.student_display_name" class="flex items-center gap-4 rounded-lg px-3 py-2.5" :class="row.rank <= 3 ? 'bg-parchment-100' : 'bg-ink-50/50'">
                        <span class="w-8 text-center text-sm font-bold text-ink-500">{{ row.rank }}</span>
                        <Icon v-if="row.rank === 1" name="trophy" :size="18" class="text-amber-500" />
                        <Icon v-else name="user" :size="18" class="text-ink-300" />
                        <span class="flex-1 font-medium text-ink-800" dir="auto">{{ row.student_display_name }}</span>
                        <span class="text-sm text-ink-600">{{ row.score }}</span>
                        <span class="text-xs text-ink-400">{{ row.percentage }}%</span>
                    </div>
                    <Pagination v-if="leaderboard?.meta" :meta="leaderboard.meta" @change="loadLeaderboard" />
                </div>
                <EmptyState v-else icon="trophy" :title="$t('competitions.noRankingsTitle')" :message="$t('competitions.studentNoRankingsMessage')" />
            </AppCard>
        </template>
    </div>
</template>
