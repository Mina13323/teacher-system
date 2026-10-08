import { describe, expect, it } from 'vitest';
import { createKeyedQueue } from './keyedQueue';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('createKeyedQueue', () => {
    it('runs tasks for the same key one at a time, in order', async () => {
        const queue = createKeyedQueue();
        const first = deferred();
        const started = [];

        const a = queue.run(1, () => { started.push('a'); return first.promise; });
        const b = queue.run(1, () => { started.push('b'); return 'b-done'; });
        await flush();
        expect(started).toEqual(['a']);

        first.resolve('a-done');
        await expect(a).resolves.toBe('a-done');
        await expect(b).resolves.toBe('b-done');
        expect(started).toEqual(['a', 'b']);
    });

    it('lets different keys run in parallel', async () => {
        const queue = createKeyedQueue();
        const blocker = deferred();
        const started = [];

        queue.run(1, () => { started.push(1); return blocker.promise; });
        queue.run(2, () => { started.push(2); });
        await flush();

        expect(started).toEqual([1, 2]);
        blocker.resolve();
    });

    it('does not let a failure block later tasks, and still reports it', async () => {
        const queue = createKeyedQueue();
        const failing = queue.run('q', () => Promise.reject(new Error('network')));
        const next = queue.run('q', () => 'sent');

        await expect(failing).rejects.toThrow('network');
        await expect(next).resolves.toBe('sent');
    });

    it('a newer save sent after an older one cannot be overtaken', async () => {
        // Simulates the server applying answers in arrival order.
        const queue = createKeyedQueue();
        const server = [];
        const slow = deferred();

        const older = queue.run(7, async () => { await slow.promise; server.push('A'); });
        const newer = queue.run(7, async () => { server.push('B'); });
        await flush();
        slow.resolve();
        await Promise.all([older, newer]);

        expect(server).toEqual(['A', 'B']);
    });

    it('forgets idle keys', async () => {
        const queue = createKeyedQueue();
        await queue.run(1, () => 'x');
        await flush();
        expect(queue.busyKeys()).toBe(0);
    });
});
