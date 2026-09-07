<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAsync } from '@/composables/useAsync';
import { student } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppCard from '@/components/ui/AppCard.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const starting = ref(false);

const { loading, error, data, run } = useAsync(() => student.exam(route.params.id));
onMounted(() => run());

async function start() {
    starting.value = true;
    try {
        const attempt = await student.startExam(route.params.id);
        router.push(`/student/attempts/${attempt.id}`);
    } catch (e) {
        toast.error(e.message);
    } finally {
        starting.value = false;
    }
}

function attemptTone(status) {
    return { submitted: 'success', in_progress: 'warning', expired: 'danger' }[status] || 'neutral';
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <LoadingSpinner v-if="loading" />
        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error.message }}</div>
        <template v-else>
            <div>
                <router-link to="/student/exams" class="text-sm font-medium text-terracotta-600 hover:underline">← All exams</router-link>
                <h1 class="mt-2 text-2xl font-bold text-ink-900">{{ data.title }}</h1>
                <p class="mt-1 text-ink-600">{{ data.description }}</p>
            </div>

            <AppCard title="About this exam">
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-ink-400">Duration</dt><dd class="font-semibold text-ink-800">{{ data.duration_minutes }} min</dd></div>
                    <div><dt class="text-ink-400">Questions</dt><dd class="font-semibold text-ink-800">{{ data.questions_count }}</dd></div>
                    <div><dt class="text-ink-400">Pass mark</dt><dd class="font-semibold text-ink-800">{{ data.pass_percentage }}%</dd></div>
                    <div><dt class="text-ink-400">Attempts</dt><dd class="font-semibold text-ink-800">{{ data.max_attempts }}</dd></div>
                </dl>
                <div class="mt-6">
                    <AppButton :loading="starting" size="lg" @click="start">Start exam</AppButton>
                </div>
            </AppCard>

            <AppCard v-if="data.my_attempts?.length" title="Your attempts">
                <div class="divide-y divide-ink-100">
                    <div v-for="a in data.my_attempts" :key="a.attempt_number" class="flex items-center gap-3 py-3">
                        <span class="text-sm font-semibold text-ink-700">Attempt {{ a.attempt_number }}</span>
                        <AppBadge :tone="attemptTone(a.status)">{{ a.status.replace('_', ' ') }}</AppBadge>
                        <span v-if="a.percentage !== null" class="text-sm text-ink-600">{{ a.percentage }}%</span>
                        <span class="ml-auto text-xs text-ink-400">{{ a.submitted_at ? new Date(a.submitted_at).toLocaleString() : new Date(a.started_at).toLocaleString() }}</span>
                    </div>
                </div>
            </AppCard>
        </template>
    </div>
</template>
