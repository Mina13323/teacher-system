<script setup>
import { ref, onMounted } from 'vue';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import StatCard from '@/components/ui/StatCard.vue';

const toast = useToast();
const loading = ref(true);
const students = ref([]);
const meta = ref(null);
const error = ref('');

async function load() {
    loading.value = true;
    try {
        const res = toList(await teacher.students({ per_page: 6 }));
        students.value = res.items;
        meta.value = res.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">{{ $t('dashboard.assistantTitle') }}</h1>
                <p class="text-sm text-ink-500">{{ $t('dashboard.assistantSubtitle') }}</p>
            </div>
            <router-link to="/assistant/students/new"><AppButton>{{ $t('dashboard.addStudent') }}</AppButton></router-link>
        </div>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard :label="$t('dashboard.statStudents')" :value="meta?.total ?? students.length" icon="users" tone="terracotta" />
                <StatCard :label="$t('dashboard.activeStudents')" :value="students.filter((s) => s.is_active).length + (meta && meta.total > students.length ? '+' : '')" icon="user" tone="emerald" />
                <StatCard :label="$t('dashboard.thisPage')" :value="students.length" icon="users" tone="sky" />
            </div>

            <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
                <div class="px-5 py-4"><h2 class="font-semibold text-ink-900">{{ $t('dashboard.recentStudents') }}</h2></div>
                <EmptyState v-if="!students.length" icon="users" :title="$t('dashboard.noStudentsYet')" :message="$t('students.createHint')">
                    <router-link to="/assistant/students/new"><AppButton>{{ $t('dashboard.addStudent') }}</AppButton></router-link>
                </EmptyState>
                <div v-else class="divide-y divide-ink-100">
                    <router-link v-for="s in students" :key="s.id" :to="`/assistant/students/${s.id}`" class="flex items-center gap-3 px-5 py-3.5 hover:bg-ink-50">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-ink-100 text-sm font-bold text-ink-600">{{ (s.name || 'U').slice(0, 1) }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink-800" dir="auto">{{ s.name }}</p>
                            <p class="truncate text-xs text-ink-400">{{ s.email }}</p>
                        </div>
                        <AppBadge :tone="s.is_active ? 'success' : 'neutral'">{{ s.is_active ? $t('status.active') : $t('status.inactive') }}</AppBadge>
                    </router-link>
                </div>
            </div>
        </template>
    </div>
</template>
