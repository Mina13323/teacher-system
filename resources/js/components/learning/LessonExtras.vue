<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { learning, student } from '@/api';
import { useToast } from '@/composables/toast';
import AppButton from '@/components/ui/AppButton.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';

/**
 * PHASE 4 — lesson-level learning tools: private notes, Q&A thread and a
 * personal bookmark. Notes are owner-only; Q&A is answered by the teacher /
 * assistants; deleted messages keep their thread slot ("[removed]").
 */
const props = defineProps({
    lessonId: { type: [Number, String], required: true },
});

const { t } = useI18n();
const toast = useToast();

const notes = ref([]);
const noteBody = ref('');
const questions = ref([]);
const questionBody = ref('');
const replyDrafts = ref({});
const bookmarked = ref(false);
const busy = ref(false);
/** The id of this lesson's bookmark, when known (saves a lookup on removal). */
const bookmarkId = ref(null);

async function loadNotes() {
    const n = await student.notes({ lesson_id: props.lessonId });
    notes.value = (n.data || n || []);
}

async function loadQuestions() {
    const q = await learning.questions(props.lessonId);
    questions.value = (q.data || q || []);
}

async function loadAll() {
    try {
        const [n, q, b] = await Promise.all([
            student.notes({ lesson_id: props.lessonId }),
            learning.questions(props.lessonId),
            student.bookmarks(),
        ]);
        notes.value = (n.data || n || []);
        questions.value = (q.data || q || []);
        const list = (b.data || b || []);
        const mine = list.find((x) => String(x.lesson_id) === String(props.lessonId) && !x.video_id);
        bookmarked.value = Boolean(mine);
        bookmarkId.value = mine?.id ?? null;
    } catch {
        // panel stays usable; individual actions surface errors
    }
}

async function addNote() {
    if (!noteBody.value.trim()) return;
    busy.value = true;
    try {
        await student.saveNote({ lesson_id: props.lessonId, body: noteBody.value });
        noteBody.value = '';
        toast.success(t('lessonExtras.noteSaved'));
        await loadNotes();
    } catch (e) {
        toast.error(e.message);
    } finally {
        busy.value = false;
    }
}

async function removeNote(id) {
    try {
        await student.deleteNote(id);
        await loadNotes();
    } catch (e) {
        toast.error(e.message);
    }
}

async function ask() {
    if (!questionBody.value.trim()) return;
    busy.value = true;
    try {
        await learning.ask(props.lessonId, { body: questionBody.value });
        questionBody.value = '';
        toast.success(t('lessonExtras.asked'));
        await loadQuestions();
    } catch (e) {
        toast.error(e.message);
    } finally {
        busy.value = false;
    }
}

async function reply(questionId) {
    const body = (replyDrafts.value[questionId] || '').trim();
    if (!body) return;
    try {
        await learning.reply(questionId, { body });
        replyDrafts.value[questionId] = '';
        await loadQuestions();
    } catch (e) {
        toast.error(e.message);
    }
}

async function toggleBookmark() {
    try {
        if (bookmarked.value) {
            let id = bookmarkId.value;
            if (!id) {
                const list = await student.bookmarks();
                id = (list.data || list || []).find((x) => String(x.lesson_id) === String(props.lessonId) && !x.video_id)?.id ?? null;
            }
            if (id) await student.deleteBookmark(id);
            bookmarked.value = false;
            bookmarkId.value = null;
            toast.success(t('lessonExtras.bookmarkRemoved'));
        } else {
            const added = await student.addBookmark({ lesson_id: props.lessonId });
            bookmarked.value = true;
            bookmarkId.value = (added?.data || added)?.id ?? null;
            toast.success(t('lessonExtras.bookmarked'));
        }
    } catch (e) {
        toast.error(e.message);
    }
}

onMounted(loadAll);
</script>

<template>
    <div class="space-y-6">
        <!-- Bookmark -->
        <div class="flex items-center gap-2">
            <AppButton variant="outline" size="sm" @click="toggleBookmark">
                {{ bookmarked ? '★ ' + t('lessonExtras.bookmarkedBtn') : '☆ ' + t('lessonExtras.bookmarkBtn') }}
            </AppButton>
        </div>

        <!-- Private notes -->
        <section class="rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-ink-800">📝 {{ $t('lessonExtras.notesTitle') }}</h2>
            <p class="mt-0.5 text-xs text-ink-400">{{ $t('lessonExtras.notesPrivate') }}</p>
            <ul class="mt-3 space-y-2">
                <li v-for="n in notes" :key="n.id" class="flex items-start gap-2 rounded-lg bg-ink-50 px-3 py-2 text-sm">
                    <p class="min-w-0 flex-1 whitespace-pre-wrap text-ink-700" dir="auto">{{ n.body }}</p>
                    <button class="text-rose-500 text-xs hover:underline" @click="removeNote(n.id)">{{ $t('common.delete') }}</button>
                </li>
            </ul>
            <div class="mt-3 space-y-2">
                <AppTextarea v-model="noteBody" :rows="2" :placeholder="$t('lessonExtras.notePlaceholder')" id="lesson-note" />
                <div class="flex justify-end"><AppButton size="sm" variant="outline" :loading="busy" @click="addNote">{{ $t('lessonExtras.saveNote') }}</AppButton></div>
            </div>
        </section>

        <!-- Q&A -->
        <section class="rounded-xl border border-ink-100 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-ink-800">💬 {{ $t('lessonExtras.qaTitle') }}</h2>
            <div class="mt-3 space-y-3">
                <div v-for="q in questions" :key="q.id" class="rounded-lg border border-ink-100 p-3">
                    <p class="text-sm text-ink-800" dir="auto">
                        <span class="font-semibold">{{ q.user?.name }}:</span> {{ q.body }}
                    </p>
                    <div v-if="q.replies?.length" class="mt-2 space-y-1 border-s-2 border-ink-100 ps-3">
                        <p v-for="r in q.replies" :key="r.id" class="text-sm text-ink-600" dir="auto">
                            <span class="font-medium">{{ r.user?.name }}:</span> {{ r.body }}
                        </p>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <input
                            v-model="replyDrafts[q.id]"
                            type="text"
                            class="flex-1 rounded-lg border border-ink-200 px-3 py-1.5 text-sm"
                            :placeholder="$t('lessonExtras.replyPlaceholder')"
                        />
                        <AppButton size="sm" variant="ghost" @click="reply(q.id)">{{ $t('lessonExtras.replyBtn') }}</AppButton>
                    </div>
                </div>
                <p v-if="!questions.length" class="text-xs text-ink-400">{{ $t('lessonExtras.qaEmpty') }}</p>
            </div>
            <div class="mt-3 space-y-2">
                <AppTextarea v-model="questionBody" :rows="2" :placeholder="$t('lessonExtras.askPlaceholder')" id="lesson-question" />
                <div class="flex justify-end"><AppButton size="sm" :loading="busy" @click="ask">{{ $t('lessonExtras.askBtn') }}</AppButton></div>
            </div>
        </section>
    </div>
</template>
