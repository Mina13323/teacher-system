/**
 * Tabs whose content is fetched the first time the tab is opened, then kept.
 *
 * - Opening a tab that is loaded, or already loading, sends nothing.
 * - `reload(name)` refetches one tab (after the student adds a note, asks a
 *   question...), never the others.
 * - A failed load leaves the tab unloaded, so opening it again retries.
 * - After `dispose()` (the lesson changed or the panel unmounted) no late
 *   response is applied.
 * - Overlapping loads of one tab: only the newest response is applied.
 */
export function createLazyTabs({ loaders, apply }) {
    const loaded = new Set();
    const inFlight = new Map();
    const seq = new Map();
    let disposed = false;

    function run(name) {
        const loader = loaders[name];
        if (!loader || disposed) return Promise.resolve(false);
        const mine = (seq.get(name) || 0) + 1;
        seq.set(name, mine);
        const promise = Promise.resolve()
            .then(() => loader())
            .then((data) => {
                if (disposed || seq.get(name) !== mine) return false;
                apply(name, data);
                loaded.add(name);
                return true;
            }, (e) => {
                if (disposed || seq.get(name) !== mine) return false;
                throw e;
            })
            .finally(() => {
                if (inFlight.get(name) === promise) inFlight.delete(name);
            });
        inFlight.set(name, promise);
        return promise;
    }

    function open(name) {
        if (loaded.has(name)) return Promise.resolve(true);
        if (inFlight.has(name)) return inFlight.get(name);
        return run(name);
    }

    return {
        open,
        reload: run,
        isLoaded: (name) => loaded.has(name),
        isLoading: (name) => inFlight.has(name),
        dispose() {
            disposed = true;
            inFlight.clear();
        },
    };
}
