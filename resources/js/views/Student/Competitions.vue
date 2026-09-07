<script setup>
import { onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { student, toList } from '@/api';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Icon from '@/components/ui/Icon.vue';

const { loading, error, data, run } = useAsync(async () => toList(await student.competitions()));
onMounted(() => run());

function statusTone(s) {
    return { active: 'success', published: 'primary', ended: 'neutral', draft: 'neutral', archived: 'neutral' }[s] || 'neutral';
}
</script>

<template>
    <div class="space-y-6">
        <h1 class="text-2xl font-bold text-ink-900">Competitions</h1>

        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>
        <EmptyState v-else-if="!data.items.length" icon="trophy" title="No competitions yet" message="Competitions from your courses will appear here once open." />
        <div v-else class="space-y-3">
            <router-link v-for="c in data.items" :key="c.id" :to="`/student/competitions/${c.id}`" class="group block">
                <div class="flex items-center gap-4 rounded-xl border border-ink-100 bg-white p-5 shadow-sm transition group-hover:shadow-md">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                        <Icon name="trophy" :size="24" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="font-semibold text-ink-900 group-hover:text-terracotta-700">{{ c.title }}</h3>
                        <p class="text-sm text-ink-500">{{ c.exam_title }}</p>
                        <p class="text-xs text-ink-400">{{ c.participants_count }} participant(s)</p>
                    </div>
                    <div class="hidden sm:block">
                        <AppBadge :tone="statusTone(c.status)">{{ c.status }}</AppBadge>
                        <p v-if="c.is_joined" class="mt-1 text-center text-xs font-medium text-emerald-600">Joined</p>
                    </div>
                </div>
            </router-link>
        </div>
    </div>
</template>
