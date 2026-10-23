/**
 * Loads everything the student lesson page shows first, in one request:
 * GET /student/lessons/{id}?include=progress,videos,bookmark returns the
 * lesson with its attachments, the student's progress, the published video
 * list (metadata only: a playable reference still comes only from the
 * protected playback endpoint) and the student's bookmark on the lesson.
 *
 * Notes and Q&A are not loaded here; the lesson tools load them when their
 * tab is opened (see lazyTabs.js).
 *
 * Each load gets a sequence number. A response for a lesson the page has
 * since moved away from resolves to null, so it never overwrites the newer
 * lesson's state.
 *
 * If the server predates the combined response (no `videos` key), the
 * progress and videos are read from their own endpoints as before, and the
 * bookmark is left unknown (undefined) for the lesson tools to look up.
 */
export const LESSON_PAGE_INCLUDE = 'progress,videos,bookmark';

function listOf(res) {
    if (Array.isArray(res)) return res;
    if (res && Array.isArray(res.data)) return res.data;
    return [];
}

function attachmentsOf(lesson) {
    return (lesson?.attachments || []).map((a) => ({ id: a.id, title: a.title, size: a.file_size ?? a.size, type: a.file_mime ?? a.type }));
}

export function createLessonPageLoader({ api }) {
    let seq = 0;

    async function load(lessonId) {
        const mine = ++seq;
        let res;
        try {
            res = await api.lessonPage(lessonId);
        } catch (e) {
            // A failure for a lesson the page has left is not this page's error.
            if (mine !== seq) return null;
            throw e;
        }
        if (mine !== seq) return null;

        const lesson = res && res.id !== undefined ? res : (res?.lesson || null);
        let progress = res?.progress ?? null;
        let videos = listOf(res?.videos);
        let bookmark = res && Object.prototype.hasOwnProperty.call(res, 'bookmark') ? res.bookmark : undefined;

        if (!res || !Object.prototype.hasOwnProperty.call(res, 'videos')) {
            const [p, v] = await Promise.all([
                api.lessonProgress(lessonId).catch(() => null),
                api.lessonVideos(lessonId).catch(() => null),
            ]);
            if (mine !== seq) return null;
            progress = p;
            videos = listOf(v);
            bookmark = undefined;
        }

        return { lessonId: String(lessonId), lesson, attachments: attachmentsOf(lesson), progress, videos, bookmark };
    }

    return { load };
}
