import { describe, expect, it, vi } from 'vitest';
import { enterFullscreenForStart, leaveFullscreen } from './examFullscreen';

function fakeDocument({ request, fullscreen = null } = {}) {
    const doc = { fullscreenElement: fullscreen, documentElement: {} };
    if (request) doc.documentElement.requestFullscreen = request;
    doc.exitFullscreen = vi.fn(() => Promise.resolve());
    return doc;
}

describe('enterFullscreenForStart', () => {
    it('asks for fullscreen synchronously, inside the click, when the rules require it', async () => {
        const request = vi.fn(() => Promise.resolve());
        const doc = fakeDocument({ request });
        const pending = enterFullscreenForStart({ fullscreen_required: true }, doc);
        expect(request).toHaveBeenCalledTimes(1); // before any await
        await expect(pending).resolves.toBe(true);
    });

    it('does nothing when the exam does not require fullscreen', async () => {
        const request = vi.fn(() => Promise.resolve());
        await expect(enterFullscreenForStart({ fullscreen_required: false }, fakeDocument({ request }))).resolves.toBe(false);
        await expect(enterFullscreenForStart(null, fakeDocument({ request }))).resolves.toBe(false);
        expect(request).not.toHaveBeenCalled();
    });

    it('treats a refused or unsupported request as "not fullscreen", never as an error', async () => {
        const refused = fakeDocument({ request: vi.fn(() => Promise.reject(new Error('denied'))) });
        await expect(enterFullscreenForStart({ fullscreen_required: true }, refused)).resolves.toBe(false);

        const throwing = fakeDocument({ request: vi.fn(() => { throw new TypeError('not allowed'); }) });
        await expect(enterFullscreenForStart({ fullscreen_required: true }, throwing)).resolves.toBe(false);

        await expect(enterFullscreenForStart({ fullscreen_required: true }, fakeDocument())).resolves.toBe(false);
    });

    it('does not ask again when the page is already fullscreen', async () => {
        const request = vi.fn();
        await expect(enterFullscreenForStart({ fullscreen_required: true }, fakeDocument({ request, fullscreen: {} }))).resolves.toBe(true);
        expect(request).not.toHaveBeenCalled();
    });
});

describe('leaveFullscreen', () => {
    it('exits only when fullscreen', () => {
        const on = fakeDocument({ fullscreen: {} });
        leaveFullscreen(on);
        expect(on.exitFullscreen).toHaveBeenCalledTimes(1);

        const off = fakeDocument();
        leaveFullscreen(off);
        expect(off.exitFullscreen).not.toHaveBeenCalled();
    });
});
