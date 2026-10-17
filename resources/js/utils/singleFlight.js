/**
 * Wraps an async function so concurrent calls share one in-flight call.
 *
 * Callers that arrive while a call is running get the same promise (same
 * result or same error). Nothing is cached once it settles: the next call
 * after that starts a fresh request.
 */
export function singleFlight(fn) {
    let inFlight = null;

    return function run(...args) {
        if (inFlight) return inFlight;
        inFlight = Promise.resolve()
            .then(() => fn(...args))
            .finally(() => {
                inFlight = null;
            });
        return inFlight;
    };
}
