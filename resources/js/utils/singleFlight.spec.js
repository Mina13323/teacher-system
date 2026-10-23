import { describe, expect, it, vi } from 'vitest';
import { singleFlight } from './singleFlight';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

/**
 * Mirrors ExamTake's handleRejection: every 422 re-reads the attempt through
 * the shared refresh and applies whatever the server returned.
 */
function createRejectionHandler(getAttempt) {
    const refresh = singleFlight((id) => getAttempt(id));
    const applied = [];
    let fallbacks = 0;

    async function handleRejection(e) {
        if (e?.status === 422) {
            try {
                const fresh = await refresh(7);
                applied.push(fresh);
                return fresh;
            } catch {
                /* falls through to the generic message */
            }
        }
        fallbacks += 1;
        return null;
    }

    return { handleRejection, applied, fallbacks: () => fallbacks };
}

const rejected = () => Object.assign(new Error('This attempt has expired.'), { status: 422 });

describe('single-flight attempt refresh on 422', () => {
    it('sends exactly one GET for 5 simultaneous 422s', async () => {
        const pending = deferred();
        const getAttempt = vi.fn(() => pending.promise);
        const { handleRejection } = createRejectionHandler(getAttempt);

        const calls = Array.from({ length: 5 }, () => handleRejection(rejected()));
        pending.resolve({ id: 7, status: 'expired' });
        await Promise.all(calls);

        expect(getAttempt).toHaveBeenCalledTimes(1);
        expect(getAttempt).toHaveBeenCalledWith(7);
    });

    it('gives every caller the same refresh result', async () => {
        const snapshot = { id: 7, status: 'expired' };
        const getAttempt = vi.fn().mockResolvedValue(snapshot);
        const { handleRejection, applied } = createRejectionHandler(getAttempt);

        const results = await Promise.all(Array.from({ length: 5 }, () => handleRejection(rejected())));

        results.forEach((result) => expect(result).toBe(snapshot));
        expect(applied).toHaveLength(5);
    });

    it('lets a later 422 trigger a new GET once the first refresh finished', async () => {
        const getAttempt = vi.fn()
            .mockResolvedValueOnce({ id: 7, status: 'in_progress' })
            .mockResolvedValueOnce({ id: 7, status: 'expired' });
        const { handleRejection } = createRejectionHandler(getAttempt);

        const first = await handleRejection(rejected());
        const second = await handleRejection(rejected());

        expect(getAttempt).toHaveBeenCalledTimes(2);
        expect(first.status).toBe('in_progress');
        expect(second.status).toBe('expired');
    });

    it('hands a refresh failure to every waiting caller, then allows a retry', async () => {
        const pending = deferred();
        const getAttempt = vi.fn()
            .mockImplementationOnce(() => pending.promise)
            .mockResolvedValueOnce({ id: 7, status: 'expired' });
        const { handleRejection, fallbacks } = createRejectionHandler(getAttempt);

        const calls = Array.from({ length: 3 }, () => handleRejection(rejected()));
        pending.reject(new Error('Network Error'));
        const results = await Promise.all(calls);

        expect(results).toEqual([null, null, null]);
        expect(fallbacks()).toBe(3);
        expect(getAttempt).toHaveBeenCalledTimes(1);

        // The failure is not cached.
        expect(await handleRejection(rejected())).toEqual({ id: 7, status: 'expired' });
        expect(getAttempt).toHaveBeenCalledTimes(2);
    });

    it('does not refresh for a non-422 error', async () => {
        const getAttempt = vi.fn();
        const { handleRejection } = createRejectionHandler(getAttempt);

        await handleRejection(Object.assign(new Error('Server Error'), { status: 500 }));

        expect(getAttempt).not.toHaveBeenCalled();
    });

    it('converts a synchronous throw into a rejected shared promise', async () => {
        const run = singleFlight(() => {
            throw new Error('boom');
        });

        await expect(run()).rejects.toThrow('boom');
        await expect(run()).rejects.toThrow('boom');
    });
});
