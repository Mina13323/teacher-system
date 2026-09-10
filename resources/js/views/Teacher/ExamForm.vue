<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppButton from '@/components/ui/AppButton.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();
const examId = route.params.id;

const form = reactive({ title: '', description: '', duration_minutes: 30, pass_percentage: 50, max_attempts: 1, shuffle_questions: false, shuffle_options: false, show_result_immediately: true });
const errors = ref({});
const loading = ref(true);
const saving = ref(false);

onMounted(async () => {
    try {
        const e = await teacher.exam(examId);
        form.title = e.title;
        form.description = e.description || '';
        form.duration_minutes = e.duration_minutes || 30;
        form.pass_percentage = e.pass_percentage ?? 50;
        form.max_attempts = e.max_attempts ?? 1;
        form.shuffle_questions = Boolean(e.shuffle_questions);
        form.shuffle_options = Boolean(e.shuffle_options);
        form.show_result_immediately = Boolean(e.show_result_immediately);
    } catch (e) {
        toast.error(e.message);
    } finally {
        loading.value = false;
    }
});

async function submit() {
    saving.value = true;
    errors.value = {};
    try {
        await teacher.updateExam(examId, form);
        toast.success(t('exams.updated'));
        router.push(`/teacher/exams/${examId}`);
    } catch (e) {
        errors.value = fieldErrors(e);
        toast.error(e.isValidation ? '' : e.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <router-link :to="`/teacher/exams/${examId}`" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('exams.backToExam') }}</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ $t('exams.editExam') }}</h1>

        <form class="space-y-5" @submit.prevent="submit">
            <AppCard :title="$t('exams.configuration')">
                <div class="space-y-4">
                    <AppInput v-model="form.title" :label="$t('exams.titleField')" required id="exam-edit-title" :error="errors.title" />
                    <AppTextarea v-model="form.description" :label="$t('exams.description')" id="exam-edit-desc" :error="errors.description" :rows="2" />
                    <div class="grid gap-4 sm:grid-cols-3">
                        <AppInput v-model="form.duration_minutes" :label="$t('exams.durationMinutes')" type="number" id="exam-edit-dur" :error="errors.duration_minutes" />
                        <AppInput v-model="form.pass_percentage" :label="$t('exams.passPercent')" type="number" id="exam-edit-pass" :error="errors.pass_percentage" />
                        <AppInput v-model="form.max_attempts" :label="$t('exams.maxAttempts')" type="number" id="exam-edit-max" :error="errors.max_attempts" />
                    </div>
                    <div class="flex flex-wrap gap-4 text-sm text-ink-700">
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.shuffle_questions" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" /> {{ $t('exams.shuffleQuestions') }}</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.shuffle_options" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" /> {{ $t('exams.shuffleOptions') }}</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.show_result_immediately" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" /> {{ $t('exams.showResult') }}</label>
                    </div>
                </div>
            </AppCard>
            <div class="flex justify-end gap-2">
                <router-link :to="`/teacher/exams/${examId}`"><AppButton variant="outline">{{ $t('common.cancel') }}</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ $t('common.saveChanges') }}</AppButton>
            </div>
        </form>
    </div>
</template>
