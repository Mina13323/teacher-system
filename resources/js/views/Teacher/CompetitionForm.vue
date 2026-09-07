<script setup>
import { reactive, ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppButton from '@/components/ui/AppButton.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const id = route.params.id;
const isEdit = computed(() => Boolean(id));

const form = reactive({ title: '', description: '', course_id: '', exam_id: '', starts_at: '', ends_at: '', max_participants: '', scoring_type: 'highest_score', ranking_type: 'score_desc' });
const errors = reactive({});
const loading = ref(true);
const saving = ref(false);

const courses = ref([]);
const exams = ref([]);

async function loadCourses() {
    const res = toList(await teacher.courses({ per_page: 100 }));
    courses.value = res.items.map((c) => ({ value: c.id, label: c.title }));
}
async function loadExams(courseId) {
    if (!courseId) { exams.value = []; return; }
    try {
        const res = toList(await teacher.exams(courseId, { per_page: 100 }));
        exams.value = res.items.map((e) => ({ value: e.id, label: e.title }));
    } catch { exams.value = []; }
}

watch(() => form.course_id, (v) => { form.exam_id = ''; loadExams(v); });

onMounted(async () => {
    try {
        await loadCourses();
        if (isEdit) {
            const c = await teacher.competition(id);
            form.title = c.title;
            form.description = c.description || '';
            form.starts_at = c.starts_at ? c.starts_at.slice(0, 16) : '';
            form.ends_at = c.ends_at ? c.ends_at.slice(0, 16) : '';
            form.max_participants = c.max_participants || '';
            form.scoring_type = c.scoring_type || 'highest_score';
            form.ranking_type = c.ranking_type || 'score_desc';
        } else {
            form.scoring_type = 'highest_score';
            form.ranking_type = 'score_desc';
        }
    } catch (e) {
        toast.error(e.message);
    } finally {
        loading.value = false;
    }
});

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        if (isEdit) {
            const payload = { title: form.title, description: form.description || null };
            if (form.starts_at) payload.starts_at = form.starts_at;
            if (form.ends_at) payload.ends_at = form.ends_at;
            if (form.max_participants) payload.max_participants = Number(form.max_participants);
            await teacher.updateCompetition(id, payload);
            toast.success('Competition updated.');
        } else {
            const payload = {
                title: form.title,
                description: form.description || null,
                exam_id: Number(form.exam_id),
                scoring_type: form.scoring_type,
                ranking_type: form.ranking_type,
            };
            if (form.starts_at) payload.starts_at = form.starts_at;
            if (form.ends_at) payload.ends_at = form.ends_at;
            if (form.max_participants) payload.max_participants = Number(form.max_participants);
            await teacher.createCompetition(payload);
            toast.success('Competition created.');
        }
        router.push('/teacher/competitions');
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        toast.error(e.isValidation ? 'Please fix the highlighted fields.' : e.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <router-link to="/teacher/competitions" class="text-sm font-medium text-terracotta-600 hover:underline">← Competitions</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ isEdit ? 'Edit competition' : 'New competition' }}</h1>

        <LoadingSpinner v-if="loading" />
        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard title="Competition details">
                <div class="space-y-4">
                    <AppInput v-model="form.title" label="Title" required id="comp-title" :error="errors.title" />
                    <AppTextarea v-model="form.description" label="Description" id="comp-desc" :error="errors.description" :rows="2" />
                    <div v-if="!isEdit" class="grid gap-4 sm:grid-cols-2">
                        <AppSelect v-model="form.course_id" label="Course" :options="courses" id="comp-course" :error="errors.exam_id" placeholder="Select a course" />
                        <AppSelect v-model="form.exam_id" label="Exam" :options="exams" id="comp-exam" :error="errors.exam_id" placeholder="Select an exam" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppInput v-model="form.starts_at" label="Starts at" type="datetime-local" id="comp-start" :error="errors.starts_at" />
                        <AppInput v-model="form.ends_at" label="Ends at" type="datetime-local" id="comp-end" :error="errors.ends_at" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <AppInput v-model="form.max_participants" label="Max participants" type="number" id="comp-max" :error="errors.max_participants" />
                        <AppSelect v-if="!isEdit" v-model="form.scoring_type" label="Scoring" :options="[{ value: 'highest_score', label: 'Highest %' }, { value: 'best_attempt', label: 'Best attempt' }]" id="comp-scoring" :error="errors.scoring_type" />
                        <AppSelect v-if="!isEdit" v-model="form.ranking_type" label="Ranking" :options="[{ value: 'score_desc', label: 'Score (desc)' }]" id="comp-ranking" :error="errors.ranking_type" />
                    </div>
                </div>
            </AppCard>
            <div class="flex justify-end gap-2">
                <router-link to="/teacher/competitions"><AppButton variant="outline">Cancel</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ isEdit ? 'Save changes' : 'Create competition' }}</AppButton>
            </div>
        </form>
    </div>
</template>
