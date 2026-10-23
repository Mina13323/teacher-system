import { describe, expect, it, vi } from 'vitest';
import { createLazyTabs } from './lazyTabs.js';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function setup(over = {}) {
    const applied = [];
    const loaders = { notes: vi.fn(async () => ['n1']), qa: vi.fn(async () => ['q1']), ...over };
    const tabs = createLazyTabs({ loaders, apply: (name, data) => applied.push([name, data]) });
    return { tabs, loaders, applied };
}

describe('lazy lesson tabs (B3)', () => {
    it('loads nothing until a tab is opened, then only that tab', async () => {
        const { tabs, loaders, applied } = setup();
        expect(loaders.notes).not.toHaveBeenCalled();
        expect(loaders.qa).not.toHaveBeenCalled();
        await tabs.open('notes');
        expect(loaders.notes).toHaveBeenCalledTimes(1);
        expect(loaders.qa).not.toHaveBeenCalled();
        expect(applied).toEqual([['notes', ['n1']]]);
    });

    it('switching back and forth between tabs never refetches', async () => {
        const { tabs, loaders } = setup();
        await tabs.open('notes');
        await tabs.open('qa');
        await tabs.open('notes');
        await tabs.open('qa');
        expect(loaders.notes).toHaveBeenCalledTimes(1);
        expect(loaders.qa).toHaveBeenCalledTimes(1);
    });

    it('rapid clicks while a tab is loading send one request', async () => {
        const d = deferred();
        const { tabs, loaders } = setup({ notes: vi.fn(() => d.promise) });
        const a = tabs.open('notes');
        const b = tabs.open('notes');
        expect(tabs.isLoading('notes')).toBe(true);
        d.resolve(['n']);
        await Promise.all([a, b]);
        expect(loaders.notes).toHaveBeenCalledTimes(1);
        expect(tabs.isLoaded('notes')).toBe(true);
    });

    it('reload refetches only the tab that changed', async () => {
        const { tabs, loaders } = setup();
        await tabs.open('notes');
        await tabs.open('qa');
        await tabs.reload('notes');
        expect(loaders.notes).toHaveBeenCalledTimes(2);
        expect(loaders.qa).toHaveBeenCalledTimes(1);
    });

    it('a failed load is retried the next time the tab is opened', async () => {
        const notes = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(['n']);
        const { tabs, applied } = setup({ notes });
        await expect(tabs.open('notes')).rejects.toThrow('offline');
        expect(tabs.isLoaded('notes')).toBe(false);
        await tabs.open('notes');
        expect(notes).toHaveBeenCalledTimes(2);
        expect(applied).toEqual([['notes', ['n']]]);
    });

    it('nothing is applied after dispose (lesson changed / panel unmounted)', async () => {
        const d = deferred();
        const { tabs, applied } = setup({ notes: vi.fn(() => d.promise) });
        const pending = tabs.open('notes');
        tabs.dispose();
        d.resolve(['late']);
        await expect(pending).resolves.toBe(false);
        expect(applied).toEqual([]);
        await expect(tabs.open('qa')).resolves.toBe(false);
    });

    it('overlapping loads of one tab apply only the newest response', async () => {
        const older = deferred();
        const newer = deferred();
        const notes = vi.fn().mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
        const { tabs, applied } = setup({ notes });
        const a = tabs.open('notes');
        const b = tabs.reload('notes'); // a note was added meanwhile
        newer.resolve(['n1', 'n2']);
        older.resolve(['n1']);
        await Promise.all([a, b]);
        expect(applied).toEqual([['notes', ['n1', 'n2']]]);
    });
});
