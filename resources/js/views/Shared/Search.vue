<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { learning } from '@/api';
import { useToast } from '@/composables/toast';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';

/**
 * PHASE 4 §34 — role-scoped search. The server decides what each role may
 * see; this view just renders grouped results with direct navigation
 * (no dead ends — every row opens the thing it names).
 */
const { t } = useI18n();
const router = useRouter();
const toast = useToast();

const query = ref('');
const busy = ref(false);
const results = ref(null);
const searched = ref(false);

async function search() {
    if (query.value.trim().length < 2) return;
    busy.value = true;
    searched.value = true;
    try {
        const res = await learning.search({ q: query.value.trim() });
        results.value = res.results || {};
    } catch (e) {
        toast.error(e.message);
        results.value = {};
    } finally {
        busy.value = false;
    }
}

function open(item) {
    if (item.url) router.push(item.url);
}

const labels = {
    courses: 'nav.courses',
    lessons: 'nav.lesson',
    videos: 'search.videos',
    exams: 'nav.exams',
    students: 'nav.students',
};
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <h1 class="text-2xl font-bold text-ink-900">{{ $t('search.title') }}</h1>

        <form class="flex items-center gap-2" @submit.prevent="search">
            <input
                v-model="query"
                type="search"
                class="flex-1 rounded-xl border border-ink-200 px-4 py-2.5 text-sm"
                :placeholder="$t('search.placeholder')"
            />
            <button type="submit" class="rounded-xl bg-terracotta-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-terracotta-700">
                {{ $t('search.button') }}
            </button>
        </form>

        <LoadingSpinner v-if="busy" />
        <EmptyState
            v-else-if="searched && (!results || !Object.values(results).some((g) => g.length))"
            icon="compass"
            :title="$t('search.empty')"
            :message="$t('search.emptyHint')"
        />
        <div v-else-if="results" class="space-y-5">
            <section v-for="(group, type) in results" :key="type">
                <template v-if="group.length">
                    <h2 class="mb-2 text-sm font-semibold text-ink-500">{{ $t(labels[type] || 'search.other') }}</h2>
                    <div class="space-y-2">
                        <button
                            v-for="item in group"
                            :key="item.type + '-' + item.id"
                            class="flex w-full items-center gap-3 rounded-xl border border-ink-100 bg-white px-4 py-3 text-start shadow-sm hover:border-terracotta-300"
                            @click="open(item)"
                        >
                            <span class="min-w-0 flex-1 truncate font-medium text-ink-800" dir="auto">{{ item.title }}</span>
                            <span class="text-ink-400">→</span>
                        </button>
                    </div>
                </template>
            </section>
        </div>
    </div>
</template>
