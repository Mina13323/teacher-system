<script setup>
import { onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { useNotificationsStore } from '@/stores/notifications';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Icon from '@/components/ui/Icon.vue';

const store = useNotificationsStore();
const { items, meta, loading, error } = storeToRefs(store);
const toast = useToast();

function iconFor(type) {
    if (type === 'exam_published') return 'clipboard';
    if (type === 'competition_published') return 'trophy';
    if (type === 'result_available') return 'chart';
    return 'bell';
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

onMounted(() => store.fetch());
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">Notifications</h1>
                <p class="text-sm text-ink-500">Stay updated on your courses, exams and competitions.</p>
            </div>
            <AppButton v-if="items.length" variant="outline" @click="readAll">Mark all read</AppButton>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            Could not load notifications.
        </div>
        <EmptyState
            v-else-if="!items.length"
            icon="bell"
            title="No notifications"
            message="When something happens in your courses, it will show up here."
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
                        <p class="font-semibold text-ink-900">{{ n.data?.title || 'Notification' }}</p>
                        <AppBadge v-if="!n.read_at" tone="primary">New</AppBadge>
                    </div>
                    <p class="mt-0.5 text-sm text-ink-600">{{ n.data?.message }}</p>
                    <p class="mt-1 text-xs text-ink-400">{{ new Date(n.created_at).toLocaleString() }}</p>
                </div>
                <button v-if="!n.read_at" type="button" class="shrink-0 self-center rounded-lg px-3 py-1.5 text-xs font-medium text-terracotta-600 hover:bg-terracotta-50" @click="read(n.id)">
                    Mark read
                </button>
            </div>
            <Pagination v-if="meta" :meta="meta" @change="loadPage" />
        </div>
    </div>
</template>
