<script setup>
import { reactive, ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import { useFieldErrors } from '@/composables/fieldErrors';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppModal from '@/components/ui/AppModal.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const { fieldErrors } = useFieldErrors();

const id = computed(() => {
    const raw = route.params.id;
    return raw && raw !== 'undefined' && raw !== 'new' ? String(raw) : null;
});
const isEdit = computed(() => Boolean(id.value && !route.name?.endsWith('.new')));
const authRole = computed(() => route.path.startsWith('/assistant') ? 'assistant' : 'teacher');

const form = reactive({
    name: '',
    phone: '',
    academic_year: 'secondary_1',
    academic_subject: 'both',
    capability_preset: 'ALL',
    can_access_lessons: true,
    can_take_exams: true,
    can_join_competitions: true,
    email: '',
    password: '',
    bio: '',
    avatar: '',
    course_ids: [],
});

const errors = reactive({});
const loading = ref(Boolean(id.value));
const saving = ref(false);
const courses = ref([]);

// One-time revealed credentials modal
const revealCredentials = ref(null);
const copied = ref(false);

const academicYearOptions = computed(() => [
    { value: 'secondary_1', label: t('students.secondary1') || '1st Secondary (الصف الأول الثانوي)' },
    { value: 'secondary_2', label: t('students.secondary2') || '2nd Secondary (الصف الثاني الثانوي)' },
    { value: 'secondary_3', label: t('students.secondary3') || '3rd Secondary (الصف الثالث الثانوي)' },
]);

const subjectOptions = computed(() => [
    { value: 'history', label: t('subject.history') },
    { value: 'geography', label: t('subject.geography') },
    { value: 'both', label: t('subject.both') },
]);

const presetOptions = computed(() => [
    { value: 'ALL', label: t('students.presetAll') || 'الكل (دروس + امتحانات + مسابقات)' },
    { value: 'LESSONS_ONLY', label: t('students.presetLessonsOnly') || 'دروس فقط' },
    { value: 'EXAMS_ONLY', label: t('students.presetExamsOnly') || 'امتحانات فقط' },
    { value: 'COMPETITIONS_ONLY', label: t('students.presetCompetitionsOnly') || 'مسابقات فقط' },
    { value: 'NONE', label: t('students.presetNone') || 'بدون صلاحيات' },
    { value: 'CUSTOM', label: t('students.presetCustom') || 'مخصص' },
]);

function onPresetChange(val) {
    form.capability_preset = val;
    if (val === 'ALL') {
        form.can_access_lessons = true;
        form.can_take_exams = true;
        form.can_join_competitions = true;
    } else if (val === 'LESSONS_ONLY') {
        form.can_access_lessons = true;
        form.can_take_exams = false;
        form.can_join_competitions = false;
    } else if (val === 'EXAMS_ONLY') {
        form.can_access_lessons = false;
        form.can_take_exams = true;
        form.can_join_competitions = false;
    } else if (val === 'COMPETITIONS_ONLY') {
        form.can_access_lessons = false;
        form.can_take_exams = false;
        form.can_join_competitions = true;
    } else if (val === 'NONE') {
        form.can_access_lessons = false;
        form.can_take_exams = false;
        form.can_join_competitions = false;
    }
}

function onCapabilityToggle() {
    form.capability_preset = 'CUSTOM';
}

async function load() {
    if (isEdit.value && id.value) {
        try {
            const s = await teacher.student(id.value);
            form.name = s.name;
            form.email = s.email;
            form.phone = s.phone || '';
            form.bio = s.bio || '';
            form.avatar = s.avatar || '';
            form.academic_year = s.academic_year || 'secondary_1';
            form.academic_subject = s.academic_subject || 'both';
            form.can_access_lessons = s.can_access_lessons ?? true;
            form.can_take_exams = s.can_take_exams ?? true;
            form.can_join_competitions = s.can_join_competitions ?? true;
            form.capability_preset = s.capability_preset || 'CUSTOM';
        } catch (e) {
            toast.error(e.message);
        } finally {
            loading.value = false;
        }
    } else {
        loading.value = false;
    }

    try {
        const res = toList(await teacher.courses({ per_page: 100 }));
        courses.value = res.items.map((c) => ({ value: c.id, label: c.title }));
    } catch (e) {
        courses.value = [];
    }
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        if (isEdit.value && id.value) {
            await teacher.updateStudent(id.value, {
                name: form.name,
                email: form.email || undefined,
                phone: form.phone || null,
                bio: form.bio || null,
                avatar: form.avatar || null,
                academic_year: form.academic_year,
                academic_subject: form.academic_year === 'secondary_3' ? form.academic_subject : 'general',
                can_access_lessons: form.can_access_lessons,
                can_take_exams: form.can_take_exams,
                can_join_competitions: form.can_join_competitions,
                capability_preset: form.capability_preset,
            });
            toast.success(t('students.updated') || 'تم تحديث بيانات الطالب');
            router.push(`/${authRole.value}/students`);
        } else {
            const payload = {
                name: form.name,
                academic_year: form.academic_year,
                academic_subject: form.academic_year === 'secondary_3' ? form.academic_subject : 'general',
                can_access_lessons: form.can_access_lessons,
                can_take_exams: form.can_take_exams,
                can_join_competitions: form.can_join_competitions,
                capability_preset: form.capability_preset,
                phone: form.phone || null,
                bio: form.bio || null,
            };
            if (form.email) payload.email = form.email;
            if (form.password) payload.password = form.password;
            if (form.course_ids.length) payload.course_ids = form.course_ids;

            const res = await teacher.createStudent(payload);
            toast.success(t('students.created') || 'تم إنشاء حساب الطالب');

            if (res && res.credentials) {
                revealCredentials.value = res.credentials;
            } else {
                router.push(`/${authRole.value}/students`);
            }
        }
    } catch (e) {
        Object.assign(errors, fieldErrors(e));
        toast.error(e.isValidation ? t('common.fixFields') : e.message);
    } finally {
        saving.value = false;
    }
}

function copyAllCredentials() {
    if (!revealCredentials.value) return;
    const creds = revealCredentials.value;
    const text = t('students.credentialsCreateText', {
        code: creds.student_code,
        login: creds.login || creds.email,
        password: creds.temporary_password,
    });
    navigator.clipboard.writeText(text);
    copied.value = true;
    toast.success(t('students.copied') || 'تم النسخ');
    setTimeout(() => { copied.value = false; }, 3000);
}

function finishReveal() {
    revealCredentials.value = null;
    router.push(`/${authRole.value}/students`);
}

watch(() => route.params.id, () => {
    if (isEdit.value && id.value) {
        load();
    }
});

onMounted(async () => {
    try {
        await load();
    } catch (e) {
        toast.error(e.message);
    } finally {
        loading.value = false;
    }
});

function toggleCourse(courseId) {
    const i = form.course_ids.indexOf(courseId);
    if (i === -1) form.course_ids.push(courseId);
    else form.course_ids.splice(i, 1);
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <router-link :to="`/${authRole}/students`" class="text-sm font-medium text-terracotta-600 hover:underline">← {{ $t('nav.students') || 'الطلاب' }}</router-link>
        <h1 class="text-2xl font-bold text-ink-900">{{ isEdit ? ($t('students.editStudent') || 'تعديل بيانات الطالب') : ($t('students.addStudent') || 'إضافة طالب جديد') }}</h1>

        <LoadingSpinner v-if="loading" />
        <form v-else class="space-y-5" @submit.prevent="submit">
            <AppCard :title="$t('students.academicAndPersonal')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.name" :label="$t('students.fullName')" required id="student-name" :error="errors.name" />
                    <AppInput v-model="form.phone" :label="$t('students.phoneNumber')" id="student-phone" :error="errors.phone" />
                    <AppSelect
                        v-model="form.academic_year"
                        :label="$t('students.gradeLabel')"
                        :options="academicYearOptions"
                        id="student-academic-year"
                        :error="errors.academic_year"
                        required
                    />
                    <AppSelect
                        v-if="form.academic_year === 'secondary_3'"
                        v-model="form.academic_subject"
                        :label="$t('students.trackLabel')"
                        :options="subjectOptions"
                        id="student-academic-subject"
                        :error="errors.academic_subject"
                        required
                    />
                </div>
                <div class="grid gap-4 sm:grid-cols-2 mt-4">
                    <AppInput v-model="form.avatar" :label="$t('students.avatarLabel')" id="student-avatar" :error="errors.avatar" placeholder="https://…" />
                </div>
                <div class="mt-4"><AppTextarea v-model="form.bio" :label="$t('students.bioLabel')" id="student-bio" :error="errors.bio" :rows="2" /></div>
            </AppCard>

            <AppCard :title="$t('students.accessCapabilities')">
                <div class="space-y-4">
                    <div>
                        <AppSelect
                            :model-value="form.capability_preset"
                            :label="$t('students.presetLabel')"
                            :options="presetOptions"
                            id="student-preset"
                            @update:model-value="onPresetChange"
                        />
                    </div>
                    <div class="grid gap-3 pt-2 sm:grid-cols-3">
                        <label class="flex items-center gap-2 rounded-lg border border-ink-200 p-3 text-sm hover:bg-ink-50 cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="form.can_access_lessons"
                                class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400"
                                @change="onCapabilityToggle"
                            />
                            <span class="text-ink-800 font-medium">{{ $t('students.capLessonsTitle') }}</span>
                        </label>
                        <label class="flex items-center gap-2 rounded-lg border border-ink-200 p-3 text-sm hover:bg-ink-50 cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="form.can_take_exams"
                                class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400"
                                @change="onCapabilityToggle"
                            />
                            <span class="text-ink-800 font-medium">{{ $t('students.capExamsTitle') }}</span>
                        </label>
                        <label class="flex items-center gap-2 rounded-lg border border-ink-200 p-3 text-sm hover:bg-ink-50 cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="form.can_join_competitions"
                                class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400"
                                @change="onCapabilityToggle"
                            />
                            <span class="text-ink-800 font-medium">{{ $t('students.capCompetitionsTitle') }}</span>
                        </label>
                    </div>
                </div>
            </AppCard>

            <AppCard :title="$t('students.officialCredentials')">
                <div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900 mb-3">
                    ℹ️ <strong>{{ $t('students.autoGenStrong') }}</strong> {{ $t('students.autoGenHintA') }} <code>ELM-1001@student.com</code> {{ $t('students.autoGenHintB') }} <code>ELM-10012026</code>.
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.email" :label="$t('students.emailLabel')" type="email" id="student-email" :error="errors.email" autocomplete="email" :placeholder="$t('students.emailAutoPlaceholder')" />
                    <AppInput v-if="!isEdit" v-model="form.password" :label="$t('students.initialPassword')" type="password" id="student-password" :error="errors.password" autocomplete="new-password" :placeholder="$t('students.passwordAutoPlaceholder')" />
                </div>
            </AppCard>

            <AppCard v-if="!isEdit && courses.length" :title="$t('students.directEnrollment')">
                <p class="mb-3 text-sm text-ink-500">{{ $t('students.directEnrollmentHint') }}</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label v-for="c in courses" :key="c.value" class="flex items-center gap-2 rounded-lg border border-ink-200 px-3 py-2.5 text-sm hover:bg-ink-50">
                        <input type="checkbox" class="h-4 w-4 rounded border-ink-300 text-terracotta-600 focus:ring-terracotta-400" :checked="form.course_ids.includes(c.value)" @change="toggleCourse(c.value)" />
                        <span class="text-ink-800" dir="auto">{{ c.label }}</span>
                    </label>
                </div>
            </AppCard>

            <div class="flex justify-end gap-2">
                <router-link :to="`/${authRole}/students`"><AppButton variant="outline">{{ $t('common.cancel') || 'إلغاء' }}</AppButton></router-link>
                <AppButton type="submit" :loading="saving">{{ isEdit ? ($t('common.saveChanges') || 'حفظ التعديلات') : ($t('students.createStudent') || 'إنشاء حساب الطالب') }}</AppButton>
            </div>
        </form>

        <!-- One-Time Revealed Credentials Modal -->
        <AppModal :open="Boolean(revealCredentials)" :title="$t('students.credentialsModalTitle')" size="md" @close="finishReveal">
            <div v-if="revealCredentials" class="space-y-4">
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-3.5 text-sm text-amber-900 flex items-start gap-2">
                    <span class="text-lg">⚠️</span>
                    <p class="font-medium">{{ $t('students.saveCredentialsHint') }}</p>
                </div>

                <div class="rounded-xl border border-ink-200 bg-ink-50/50 p-4 space-y-3">
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('students.studentCodeLabel') }}</span>
                        <span class="text-lg font-mono font-bold text-terracotta-700 select-all">{{ revealCredentials.student_code }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('students.emailLabel') }}</span>
                        <span class="text-sm font-mono text-ink-900 select-all">{{ revealCredentials.login || revealCredentials.email }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-ink-500 block">{{ $t('students.firstPasswordLabel') }}</span>
                        <span class="text-base font-mono font-bold text-ink-900 bg-white border border-ink-200 px-3 py-1.5 rounded-lg inline-block select-all">{{ revealCredentials.temporary_password }}</span>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <AppButton variant="outline" @click="copyAllCredentials">
                        <span v-if="copied">✓ {{ $t('students.copied') }}</span>
                        <span v-else>📋 {{ $t('students.copyCredentials') }}</span>
                    </AppButton>
                    <AppButton @click="finishReveal">{{ $t('students.doneAndSave') }}</AppButton>
                </div>
            </div>
        </AppModal>
    </div>
</template>
