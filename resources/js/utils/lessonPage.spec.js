import { describe, expect, it, vi } from 'vitest';
import { createLessonPageLoader, LESSON_PAGE_INCLUDE } from './lessonPage.js';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

const combined = (id, over = {}) => ({
    id,
    title: `Lesson ${id}`,
    content: 'Body',
    attachments: [{ id: 9, title: 'Sheet', file_size: 10, file_mime: 'application/pdf' }],
    progress: { completed: false, progress_percentage: 20 },
    videos: [{ id: 1, title: 'V1' }],
    bookmark: null,
    ...over,
});

function fakeApi(over = {}) {
    return {
        lessonPage: vi.fn(async (id) => combined(Number(id))),
        lessonProgress: vi.fn(),
        lessonVideos: vi.fn(),
        ...over,
    };
}

describe('lesson page loader (B3)', () => {
    it('asks for progress, videos and the bookmark with the lesson', () => {
        expect(LESSON_PAGE_INCLUDE).toBe('progress,videos,bookmark');
    });

    it('loads lesson, attachments, progress, videos and bookmark in one request', async () => {
        const api = fakeApi();
        const page = await createLessonPageLoader({ api }).load('5');
        expect(api.lessonPage).toHaveBeenCalledTimes(1);
        expect(api.lessonProgress).not.toHaveBeenCalled();
        expect(api.lessonVideos).not.toHaveBeenCalled();
        expect(page).toEqual({
            lessonId: '5',
            lesson: expect.objectContaining({ id: 5, title: 'Lesson 5' }),
            attachments: [{ id: 9, title: 'Sheet', size: 10, type: 'application/pdf' }],
            progress: { completed: false, progress_percentage: 20 },
            videos: [{ id: 1, title: 'V1' }],
            bookmark: null,
        });
    });

    it('passes a bookmark through when the student has one', async () => {
        const api = fakeApi({ lessonPage: vi.fn(async () => combined(5, { bookmark: { id: 77 } })) });
        expect((await createLessonPageLoader({ api }).load(5)).bookmark).toEqual({ id: 77 });
    });

    it('a response for a lesson the page has left is dropped (out of order)', async () => {
        const a = deferred();
        const b = deferred();
        const api = fakeApi({ lessonPage: vi.fn().mockReturnValueOnce(a.promise).mockReturnValueOnce(b.promise) });
        const loader = createLessonPageLoader({ api });
        const first = loader.load(1);
        const second = loader.load(2);
        b.resolve(combined(2));
        a.resolve(combined(1));
        expect(await second).toEqual(expect.objectContaining({ lessonId: '2' }));
        expect(await first).toBeNull();
    });

    it('a failure for a lesson the page has left is not reported', async () => {
        const a = deferred();
        const api = fakeApi({ lessonPage: vi.fn().mockReturnValueOnce(a.promise).mockImplementation(async (id) => combined(id)) });
        const loader = createLessonPageLoader({ api });
        const first = loader.load(1);
        const second = loader.load(2);
        a.reject(Object.assign(new Error('Forbidden'), { status: 403 }));
        await expect(first).resolves.toBeNull();
        await expect(second).resolves.toEqual(expect.objectContaining({ lessonId: '2' }));
    });

    it('a failure for the current lesson is reported', async () => {
        const api = fakeApi({ lessonPage: vi.fn(async () => { throw Object.assign(new Error('Forbidden'), { status: 403 }); }) });
        await expect(createLessonPageLoader({ api }).load(1)).rejects.toThrow('Forbidden');
    });

    it('reloading the same lesson (refresh / remount) loads it again', async () => {
        const api = fakeApi();
        const loader = createLessonPageLoader({ api });
        await loader.load(3);
        await loader.load(3);
        expect(api.lessonPage).toHaveBeenCalledTimes(2);
    });

    it('falls back to the separate endpoints when the server sends the plain lesson', async () => {
        const plain = { id: 4, title: 'Old', attachments: [] };
        const api = fakeApi({
            lessonPage: vi.fn(async () => plain),
            lessonProgress: vi.fn(async () => ({ completed: true })),
            lessonVideos: vi.fn(async () => [{ id: 8 }]),
        });
        const page = await createLessonPageLoader({ api }).load(4);
        expect(page.progress).toEqual({ completed: true });
        expect(page.videos).toEqual([{ id: 8 }]);
        expect(page.bookmark).toBeUndefined();
    });
});
