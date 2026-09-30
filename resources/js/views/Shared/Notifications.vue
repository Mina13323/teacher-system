<script setup>
import { onMounted } from 'vue';
import { usePushNotifications } from '@/composables/usePushNotifications';
import { formatDateTime } from '@/utils/format';
import { storeToRefs } from 'pinia';
import { useNotificationsStore } from '@/stores/notifications';
import { useToast } from '@/composables/toast';
import { useI18n } from 'vue-i18n';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Icon from '@/components/ui/Icon.vue';

const store = useNotificationsStore();
const { items, meta, loading, error } = storeToRefs(store);
const toast = useToast();
const { t } = useI18n();

function iconFor(type) {
    if (type === 'exam_published') return 'clipboard';
    if (type === 'competition_published') return 'trophy';
    if (type === 'result_available') return 'chart';
    return 'bell';
}

/**
 * Where a notification should take the user when it points at something
 * actionable. Returning null leaves it display-only.
 *
 * Keyed on subject_type rather than the display type so a notification can be
 * re-worded without breaking its link. These three are all sent to students,
 * so the student routes are the right target; the view is shared with staff,
 * but staff never receive them.
 */
function linkFor(n) {
    const id = n.data?.subject_id;
    if (!id) return null;

    switch (n.data?.subject_type) {
        case 'exam_attempt':
            return `/student/attempts/${id}`;
        case 'exam':
            return `/student/exams/${id}`;
        case 'competition':
            return `/student/competitions/${id}`;
        default:
            return null;
    }
}

async function read(id) {
    await store.markRead(id).catch((e) => toast.error(e.message));
}

async function readAll() {
    await store.markAllRead().catch((e) => toast.error(e.message));
}

async function loadPage(page) {
    await store.fetch({ page });
}

const pushState = usePushNotifications();

async function togglePush() {
    const ok = pushState.subscribed.value ? await pushState.disable() : await pushState.enable();
    if (ok) {
        toast.success(pushState.subscribed.value ? t('notifications.pushEnabled') : t('notifications.pushDisabled'));
    } else {
        toast.error(t('notifications.pushUnavailable'));
    }
}

onMounted(() => {
    store.fetch();
    pushState.refresh();
});
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('notifications.title') }}</h1>
                <p class="text-sm text-ink-500">{{ $t('notifications.subtitle') }}</p>
            </div>
            <AppButton v-if="items.length" variant="outline" @click="readAll">{{ $t('notifications.markAllRead') }}</AppButton>
        </div>

        <!-- Web Push opt-in with graceful fallback to in-app notifications -->
        <div v-if="pushState.supported.value" class="mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-ink-100 bg-white px-4 py-3 shadow-sm">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-ink-800">{{ $t('notifications.pushTitle') }}</p>
                <p class="text-xs text-ink-400">{{ $t('notifications.pushHint') }}</p>
            </div>
            <AppButton
                :variant="pushState.subscribed.value ? 'outline' : 'primary'"
                size="sm"
                :loading="pushState.busy.value"
                @click="togglePush"
            >
                {{ pushState.subscribed.value ? $t('notifications.pushDisable') : $t('notifications.pushEnable') }}
            </AppButton>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {{ $t('notifications.loadError') }}
        </div>
        <EmptyState
            v-else-if="!items.length"
            icon="bell"
            :title="$t('notifications.empty')"
            :message="$t('notifications.emptyHint')"
        />
        <div v-else class="space-y-2">
            <div
                v-for="n in items"
                :key="n.id"
                class="flex items-start gap-3 rounded-xl border border-ink-100 bg-white p-4 shadow-sm transition"
                :class="n.read_at ? 'opacity-70' : 'border-terracotta-200 bg-terracotta-50/40'"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600">
                    <Icon :name="iconFor(n.data?.type)" :size="20" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <p class="font-semibold text-ink-900" dir="auto">{{ n.data?.title || $t('notifications.fallback') }}</p>
                        <AppBadge v-if="!n.read_at" tone="primary">{{ $t('notifications.new') }}</AppBadge>
                    </div>
                    <p class="mt-0.5 text-sm text-ink-600" dir="auto">{{ n.data?.message }}</p>
                    <p class="mt-1 text-xs text-ink-400">{{ formatDateTime(n.created_at) }}</p>
                    <router-link
                        v-if="linkFor(n)"
                        :to="linkFor(n)"
                        class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-terracotta-600 hover:text-terracotta-700"
                        @click="!n.read_at && read(n.id)"
                    >
                        {{ $t('notifications.openLink') }}
                        <Icon name="arrowRight" :size="14" />
                    </router-link>
                </div>
                <button v-if="!n.read_at" type="button" class="shrink-0 self-center rounded-lg px-3 py-1.5 text-xs font-medium text-terracotta-600 hover:bg-terracotta-50" @click="read(n.id)">
                    {{ $t('notifications.markRead') }}
                </button>
            </div>
            <Pagination v-if="meta" :meta="meta" @change="loadPage" />
        </div>
    </div>
</template>
