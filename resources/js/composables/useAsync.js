import { ref } from 'vue';

/**
 * Minimal async-state helper: loading / error / data with a `run` function that
 * handles ApiError and exposes the underlying error for page-level handlers.
 */
export function useAsync(fn, { immediate = false } = {}) {
    const loading = ref(false);
    const error = ref(null);
    const data = ref(null);

    async function run(...args) {
        loading.value = true;
        error.value = null;
        try {
            data.value = await fn(...args);
            return data.value;
        } catch (e) {
            error.value = e;
            throw e;
        } finally {
            loading.value = false;
        }
    }

    if (immediate) {
        run();
    }

    return {
        loading,
        error,
        data,
        is: (v) => loading.value === v,
        run,
        clear: () => {
            data.value = null;
            error.value = null;
        },
    };
}
