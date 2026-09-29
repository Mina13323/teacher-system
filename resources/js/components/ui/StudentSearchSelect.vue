<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { teacher, toList } from '@/api';

const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    error: { type: String, default: '' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    excludeIds: { type: [Array, Set], default: () => [] },
    academicYear: { type: String, default: '' },
    id: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'select', 'clear']);
const { t } = useI18n();

const containerRef = ref(null);
const searchInputRef = ref(null);
const searchQuery = ref('');
const searchResults = ref([]);
const loading = ref(false);
const isOpen = ref(false);
const selectedStudent = ref(null);

let debounceTimer = null;

const enrolledSet = computed(() => {
    if (props.excludeIds instanceof Set) return props.excludeIds;
    return new Set(Array.isArray(props.excludeIds) ? props.excludeIds : []);
});

function isEnrolled(studentId) {
    return enrolledSet.value.has(Number(studentId));
}

async function fetchStudents(query = '') {
    loading.value = true;
    try {
        const params = {
            per_page: 30,
            status: 'active',
        };
        if (query.trim()) {
            params.search = query.trim();
        }
        if (props.academicYear) {
            params.academic_year = props.academicYear;
        }

        const res = toList(await teacher.students(params));
        searchResults.value = res.items.filter((s) => s.is_active !== false);

        // If we have a modelValue but no selectedStudent object yet, find it in the results
        if (props.modelValue && !selectedStudent.value) {
            const match = searchResults.value.find((s) => Number(s.id) === Number(props.modelValue));
            if (match) {
                selectedStudent.value = match;
            }
        }
    } catch {
        searchResults.value = [];
    } finally {
        loading.value = false;
    }
}

function onSearchInput() {
    isOpen.value = true;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        fetchStudents(searchQuery.value);
    }, 250);
}

function onFocus() {
    if (props.disabled) return;
    isOpen.value = true;
    if (searchResults.value.length === 0) {
        fetchStudents(searchQuery.value);
    }
}

function selectStudent(student) {
    selectedStudent.value = student;
    searchQuery.value = '';
    isOpen.value = false;
    emit('update:modelValue', student.id);
    emit('select', student);
}

function clearSelection() {
    selectedStudent.value = null;
    searchQuery.value = '';
    emit('update:modelValue', '');
    emit('clear');
    isOpen.value = true;
    fetchStudents('');
    setTimeout(() => {
        searchInputRef.value?.focus();
    }, 50);
}

function handleClickOutside(event) {
    if (containerRef.value && !containerRef.value.contains(event.target)) {
        isOpen.value = false;
    }
}

function onKeyDown(event) {
    if (event.key === 'Escape') {
        isOpen.value = false;
    }
}

watch(
    () => props.modelValue,
    async (newVal) => {
        if (!newVal) {
            selectedStudent.value = null;
        } else if (!selectedStudent.value || Number(selectedStudent.value.id) !== Number(newVal)) {
            // Find in current search results or fetch
            const match = searchResults.value.find((s) => Number(s.id) === Number(newVal));
            if (match) {
                selectedStudent.value = match;
            } else {
                try {
                    const studentData = await teacher.student(newVal);
                    if (studentData) {
                        selectedStudent.value = studentData.data || studentData;
                    }
                } catch {
                    // silently keep null if fetch fails
                }
            }
        }
    },
    { immediate: true }
);

watch(
    () => props.academicYear,
    () => {
        if (isOpen.value) {
            fetchStudents(searchQuery.value);
        }
    }
);

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', onKeyDown);
    if (!props.modelValue) {
        fetchStudents('');
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('keydown', onKeyDown);
    if (debounceTimer) clearTimeout(debounceTimer);
});
</script>

<template>
    <div ref="containerRef" class="relative">
        <label v-if="label" :for="id || undefined" class="mb-1.5 block text-sm font-medium text-ink-800">
            {{ label }}<span v-if="required" class="text-terracotta-600"> *</span>
        </label>

        <!-- Selected Student Display Card -->
        <div
            v-if="selectedStudent"
            class="flex items-center justify-between rounded-lg border border-terracotta-200 bg-terracotta-50/40 p-2.5 transition sm:p-3"
            :class="error ? 'border-rose-300 bg-rose-50/30' : ''"
        >
            <div class="flex min-w-0 items-center gap-2.5">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-terracotta-100 font-bold text-terracotta-700 shadow-sm">
                    {{ (selectedStudent.name || 'U').slice(0, 1) }}
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <p class="truncate font-semibold text-ink-900" dir="auto">
                            {{ selectedStudent.name }}
                        </p>
                        <span
                            v-if="selectedStudent.student_code || selectedStudent.code"
                            class="rounded border border-terracotta-200 bg-white px-1.5 py-0.5 font-mono text-xs font-medium text-terracotta-700"
                        >
                            {{ selectedStudent.student_code || selectedStudent.code }}
                        </span>
                    </div>
                    <p class="truncate text-xs text-ink-500">
                        <span v-if="selectedStudent.academic_year_label">{{ selectedStudent.academic_year_label }}</span>
                        <span v-if="selectedStudent.academic_year_label && selectedStudent.phone"> · </span>
                        <span v-if="selectedStudent.phone" dir="ltr">{{ selectedStudent.phone }}</span>
                    </p>
                </div>
            </div>

            <button
                type="button"
                class="ms-2 inline-flex shrink-0 items-center gap-1 rounded-md border border-ink-200 bg-white px-2 py-1 text-xs font-medium text-ink-700 shadow-sm transition hover:bg-ink-50 hover:text-rose-600"
                :title="$t('courses.changeStudent') || 'تغيير الطالب'"
                :disabled="disabled"
                @click="clearSelection"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                </svg>
                <span>{{ $t('courses.changeStudent') || 'تغيير' }}</span>
            </button>
        </div>

        <!-- Search Input when no student selected -->
        <div v-else class="relative">
            <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-ink-400">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>

            <input
                :id="id || undefined"
                ref="searchInputRef"
                type="text"
                v-model="searchQuery"
                :placeholder="placeholder || $t('courses.searchStudentPlaceholder') || 'ابحث بالاسم، كود الطالب، أو الهاتف...'"
                :disabled="disabled"
                :required="required && !modelValue"
                autocomplete="off"
                class="w-full rounded-lg border bg-white py-2.5 pe-9 ps-9 text-sm text-ink-900 shadow-sm transition placeholder:text-ink-400 focus:outline-none focus:ring-2 disabled:bg-ink-50 disabled:text-ink-500"
                :class="error ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-200' : 'border-ink-200 focus:border-terracotta-500 focus:ring-terracotta-200'"
                @input="onSearchInput"
                @focus="onFocus"
            />

            <div v-if="loading" class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3 text-terracotta-600">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            <button
                v-else-if="searchQuery"
                type="button"
                class="absolute inset-y-0 end-0 flex items-center pe-3 text-ink-400 hover:text-ink-600"
                @click="searchQuery = ''; fetchStudents('');"
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                </svg>
            </button>
        </div>

        <!-- Search Results Dropdown -->
        <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="transform scale-95 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-95 opacity-0"
        >
            <div
                v-if="isOpen && !selectedStudent"
                class="absolute z-30 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-ink-200 bg-white py-1 shadow-lg focus:outline-none"
            >
                <div v-if="loading && searchResults.length === 0" class="py-6 text-center text-sm text-ink-500">
                    <div class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin text-terracotta-600" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{{ $t('common.loading') || 'جاري البحث...' }}</span>
                    </div>
                </div>

                <div v-else-if="searchResults.length === 0" class="px-4 py-6 text-center text-sm text-ink-500">
                    <p class="font-medium text-ink-700">{{ $t('courses.noStudentsFound') || 'لا توجد نتائج مطابقة للبحث' }}</p>
                    <p class="mt-0.5 text-xs text-ink-400">{{ $t('courses.searchStudentHint') || 'تأكد من كتابة الاسم أو الكود أو رقم الهاتف بشكل صحيح' }}</p>
                </div>

                <div v-else class="divide-y divide-ink-50">
                    <button
                        v-for="s in searchResults"
                        :key="s.id"
                        type="button"
                        class="flex w-full items-center justify-between px-3.5 py-2.5 text-start transition hover:bg-terracotta-50/50"
                        @click="selectStudent(s)"
                    >
                        <div class="flex min-w-0 items-center gap-2.5">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-100 text-xs font-bold text-ink-600">
                                {{ (s.name || 'U').slice(0, 1) }}
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="truncate font-medium text-ink-900" dir="auto">{{ s.name }}</span>
                                    <span
                                        v-if="s.student_code || s.code"
                                        class="rounded bg-ink-100 px-1.5 py-0.2 font-mono text-[11px] text-ink-700"
                                    >
                                        {{ s.student_code || s.code }}
                                    </span>
                                </div>
                                <p class="truncate text-xs text-ink-400">
                                    <span v-if="s.academic_year_label">{{ s.academic_year_label }}</span>
                                    <span v-if="s.academic_year_label && s.phone"> · </span>
                                    <span v-if="s.phone" dir="ltr">{{ s.phone }}</span>
                                </p>
                            </div>
                        </div>

                        <div class="ms-2 shrink-0">
                            <span
                                v-if="isEnrolled(s.id)"
                                class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700"
                            >
                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                </svg>
                                <span>{{ $t('courses.alreadyEnrolled') || 'مشترك بالفعل' }}</span>
                            </span>
                            <span
                                v-else
                                class="inline-flex rounded-md border border-ink-200 bg-white px-2 py-1 text-xs font-medium text-ink-600 shadow-sm transition group-hover:border-terracotta-300 group-hover:text-terracotta-600"
                            >
                                {{ $t('common.select') || 'اختيار' }}
                            </span>
                        </div>
                    </button>
                </div>
            </div>
        </Transition>

        <p v-if="error" :id="`${id || label}-error`" class="mt-1 text-xs font-medium text-rose-600">{{ error }}</p>
    </div>
</template>
