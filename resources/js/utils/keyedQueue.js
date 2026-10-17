/**
 * Runs tasks one at a time per key (for example per exam question), in the
 * order they were queued. Different keys still run in parallel.
 *
 * Two answer saves for the same question used to be sent concurrently, and
 * the server applies them in whichever order they reach the database, so an
 * older selection could overwrite a newer one. Queuing per question removes
 * that race. A failed task does not block the tasks queued after it.
 */
export function createKeyedQueue() {
    const tails = new Map();

    function run(key, task) {
        const previous = tails.get(key) || Promise.resolve();
        const result = previous.then(() => task());
        const tail = result.then(() => undefined, () => undefined);
        tails.set(key, tail);
        tail.then(() => {
            if (tails.get(key) === tail) tails.delete(key);
        });
        return result;
    }

    return {
        run,
        busyKeys: () => tails.size,
    };
}
