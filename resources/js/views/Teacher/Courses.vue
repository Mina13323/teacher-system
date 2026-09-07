<script setup>
import { ref, onMounted } from 'vue';
import { teacher, toList } from '@/api';
import { useToast } from '@/composables/toast';
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import Pagination from '@/components/ui/Pagination.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Icon from '@/components/ui/Icon.vue';

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
        const res = toList(await teacher.courses({ per_page: 15, page: p }));
        items.value = res.items;
        meta.value = res.meta;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function setStatus(c, publish) {
    try {
        const updated = await (publish ? teacher.publishCourse : teacher.unpublishCourse)(c.id);
        c.status = updated.status;
        toast.success(publish ? 'Course published.' : 'Course unpublished.');
    } catch (e) {
        toast.error(e.message);
    }
}

async function remove() {
    deleteBusy.value = true;
    try {
        await teacher.deleteCourse(deleteTarget.value.id);
        toast.success('Course deleted.');
        deleteTarget.value = null;
        load(1);
    } catch (e) {
        toast.error(e.message);
    } finally {
        deleteBusy.value = false;
    }
}

onMounted(() => load(1));
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink-900">Courses</h1>
                <p class="text-sm text-ink-500">Build and publish your course curriculum.</p>
            </div>
            <router-link to="/teacher/courses/new"><AppButton>New course</AppButton></router-link>
        </div>

        <div class="overflow-hidden rounded-xl border border-ink-100 bg-white shadow-sm">
            <LoadingSpinner v-if="loading" />
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>
            <EmptyState v-else-if="!items.length" icon="book" title="No courses yet" message="Create a course to build your curriculum.">
                <router-link to="/teacher/courses/new"><AppButton>New course</AppButton></router-link>
            </EmptyState>
            <div v-else class="divide-y divide-ink-100">
                <div v-for="c in items" :key="c.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600"><Icon name="book" :size="22" /></div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-900">{{ c.title }}</p>
                        <p class="text-xs text-ink-400">{{ c.units_count }} units · {{ c.lessons_count }} lessons · {{ c.enrollments_count }} enrollments</p>
                    </div>
                    <AppBadge :tone="c.status === 'published' ? 'success' : 'neutral'">{{ c.status }}</AppBadge>
                    <div class="flex flex-wrap items-center gap-2">
                        <router-link :to="`/teacher/courses/${c.id}`"><AppButton variant="outline" size="sm">Manage</AppButton></router-link>
                        <router-link :to="`/teacher/courses/${c.id}/edit`"><AppButton variant="ghost" size="sm">Edit</AppButton></router-link>
                        <AppButton v-if="c.status !== 'published'" variant="success" size="sm" @click="setStatus(c, true)">Publish</AppButton>
                        <AppButton v-else variant="outline" size="sm" @click="setStatus(c, false)">Unpublish</AppButton>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50" @click="deleteTarget = c">Delete</button>
                    </div>
                </div>
            </div>
            <div class="border-t border-ink-100 px-4 py-3"><Pagination v-if="meta" :meta="meta" @change="load" /></div>
        </div>

        <ConfirmDialog :open="Boolean(deleteTarget)" title="Delete course?" :message="`Delete “${deleteTarget?.title}” permanently? This cannot be undone.`" confirm-text="Delete" :loading="deleteBusy" @close="deleteTarget = null" @confirm="remove" />
    </div>
</template>
