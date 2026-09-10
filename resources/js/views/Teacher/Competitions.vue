<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Icon from '@/components/ui/Icon.vue';

const { t } = useI18n();
const toast = useToast();
const items = ref([]);
const meta = ref(null);
const loading = ref(true);
const error = ref('');
const deleteTarget = ref(null);
const deleteBusy = ref(false);

async function load(p = 1) {
    loading.value = true;
    error.value = '';
    try {
        const res = toList(await teacher.competitions({ per_page: 15, page: p }));
        items.value = res.items;
        meta.value = res.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function publish(c) {
    try {
        const updated = await teacher.publishCompetition(c.id);
        c.status = updated.status;
        toast.success(t('competitions.publishedToast'));
    } catch (e) {
        toast.error(e.message);
    }
}
async function archive(c) {
    try {
        const updated = await teacher.archiveCompetition(c.id);
        c.status = updated.status;
        toast.success(t('competitions.archived'));
    } catch (e) {
        toast.error(e.message);
    }
}
async function remove() {
    deleteBusy.value = true;
    try {
        await teacher.deleteCompetition(deleteTarget.value.id);
        toast.success(t('competitions.deleted'));
        deleteTarget.value = null;
        load(1);
    } catch (e) {
        toast.error(e.message);
    } finally {
        deleteBusy.value = false;
    }
}

function statusTone(s) {
    return { active: 'success', published: 'primary', ended: 'neutral', draft: 'neutral', archived: 'neutral' }[s] || 'neutral';
}

onMounted(() => load(1));
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('nav.competitions') }}</h1>
                <p class="text-sm text-ink-500">{{ $t('competitions.subtitle') }}</p>
            </div>
            <router-link to="/teacher/competitions/new"><AppButton>{{ $t('nav.newCompetition') }}</AppButton></router-link>
        </div>

        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!items.length" icon="trophy" :title="$t('competitions.emptyTitle')" :message="$t('competitions.emptyMessage')">
                <router-link to="/teacher/competitions/new"><AppButton>{{ $t('nav.newCompetition') }}</AppButton></router-link>
            </EmptyState>
            <div v-else class="divide-y divide-ink-100">
                <div v-for="c in items" :key="c.id" class="flex flex-wrap items-center gap-4 px-5 py-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600"><Icon name="trophy" :size="22" /></div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-900" dir="auto">{{ c.title }}</p>
                        <p class="text-xs text-ink-400" dir="auto">{{ c.exam?.title }} · {{ $t('competitions.participantsN', { n: c.participants_count }) }}</p>
                    </div>
                    <AppBadge :tone="statusTone(c.status)">{{ $t(`status.${c.status}`, c.status) }}</AppBadge>
                    <div class="flex flex-wrap items-center gap-2">
                        <router-link :to="`/teacher/competitions/${c.id}`"><AppButton variant="outline" size="sm">{{ $t('common.manage') }}</AppButton></router-link>
                        <router-link :to="`/teacher/competitions/${c.id}/edit`"><AppButton variant="ghost" size="sm">{{ $t('common.edit') }}</AppButton></router-link>
                        <AppButton v-if="c.status === 'draft'" variant="success" size="sm" @click="publish(c)">{{ $t('competitions.publish') }}</AppButton>
                        <AppButton v-else-if="c.status === 'ended'" variant="outline" size="sm" @click="archive(c)">{{ $t('competitions.archive') }}</AppButton>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="deleteTarget = c">{{ $t('common.delete') }}</button>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="meta" :meta="meta" @change="load" /></div>
        </div>

        <ConfirmDialog
            :open="Boolean(deleteTarget)"
            :title="$t('competitions.deleteTitle')"
            :message="$t('competitions.deleteMessage', { title: deleteTarget?.title })"
            :confirm-text="$t('common.delete')"
            :loading="deleteBusy"
            @close="deleteTarget = null"
            @confirm="remove"
        />
    </div>
</template>
