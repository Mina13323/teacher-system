<script setup>
import { ref, onMounted } from 'vue';
import { useAsync } from '@/composables/useAsync';
import { publicCatalog } from '@/api';
import { toList } from '@/api';
import Icon from '@/components/ui/Icon.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppSkeleton from '@/components/ui/AppSkeleton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import AppInput from '@/components/ui/AppInput.vue';
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue';

const page = ref(1);
const search = ref('');

function load(p = 1) {
    page.value = p;
    return run(p);
}

const { loading, error, data, run } = useAsync(async (p = 1) => {
    const res = await publicCatalog.courses({ per_page: 9, page: p });
    return toList(res);
});

onMounted(() => load(1));
</script>

<template>
    <div class="min-h-screen bg-parchment-50">
        <header class="sticky top-0 z-20 border-b border-ink-100 bg-parchment-50/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
                <div class="flex items-center gap-2">
                    <Icon name="compass" :size="22" class="text-terracotta-600" />
                    <span class="font-display text-lg font-semibold text-ink-900">{{ $t('app.brand') }}</span>
                </div>
                <nav class="flex items-center gap-3">
                    <LanguageSwitcher />
                    <router-link to="/" class="text-sm font-medium text-ink-600 hover:text-ink-900">{{ $t('app.home') }}</router-link>
                    <router-link to="/login" class="text-sm font-medium text-ink-600 hover:text-ink-900">{{ $t('auth.signIn') }}</router-link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-10">
            <h1 class="text-2xl font-bold text-ink-900">{{ $t('nav.courses') }}</h1>
            <p class="mt-1 text-ink-500">{{ $t('catalog.subtitle') }}</p>

            <div class="mt-6 max-w-md">
                <AppInput v-model="search" label="" :placeholder="$t('catalog.filter')" id="catalog-search" />
            </div>

            <div class="mt-6">
                <div v-if="loading" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <AppCard v-for="i in 6" :key="i" class="p-5"><AppSkeleton :rows="4" /></AppCard>
                </div>
                <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $t('catalog.loadError') }}</div>
                <EmptyState v-else-if="!data.items.length" icon="book" :title="$t('catalog.emptyTitle')" :message="$t('catalog.emptyMessage')" />
                <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <router-link
                        v-for="c in data.items.filter((x) => !search || x.title.toLowerCase().includes(search.toLowerCase()))"
                        :key="c.id"
                        :to="`/courses/${c.id}`"
                        class="group"
                    >
                        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm transition group-hover:shadow-md">
                            <div class="relative flex h-36 items-center justify-center bg-gradient-to-br from-ink-800 to-ink-900 text-white">
                                <Icon name="book" :size="40" class="text-terracotta-400" />
                                <span class="absolute start-3 top-3 rounded-full bg-black/30 px-2 py-0.5 text-xs font-medium text-white/90">{{ $t(`status.${c.status}`, c.status) }}</span>
                            </div>
                            <div class="p-5">
                                <h3 class="font-semibold text-ink-900 group-hover:text-terracotta-700" dir="auto">{{ c.title }}</h3>
                                <p class="mt-1 line-clamp-2 text-sm text-ink-500" dir="auto">{{ c.description }}</p>
                                <div class="mt-3 flex items-center gap-3 text-xs text-ink-400">
                                    <span>{{ $t('courses.lessonsCount', { n: c.lessons_count || 0 }) }}</span><span class="h-1 w-1 rounded-full bg-ink-300" /><span>{{ $t('courses.unitsCount', { n: c.units_count || 0 }) }}</span>
                                </div>
                            </div>
                        </div>
                    </router-link>
                </div>
                <Pagination v-if="data?.meta" :meta="data.meta" @change="load" />
            </div>
        </main>
    </div>
</template>
