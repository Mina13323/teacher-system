import { reactive } from 'vue';
import { useFieldErrors } from '@/composables/fieldErrors';

const state = reactive({
    items: [],
    nextId: 1,
});

function add(message, type = 'info', timeout = 4000) {
    const id = state.nextId++;
    state.items.push({ id, message, type });
    if (timeout > 0) {
        setTimeout(() => dismiss(id), timeout);
    }
    return id;
}

function dismiss(id) {
    const i = state.items.findIndex((x) => x.id === id);
    if (i !== -1) state.items.splice(i, 1);
}

export function useToast() {
    return {
        state,
        success: (m) => add(m, 'success'),
        error: (m) => add(m, 'error', 6000),
        info: (m) => add(m, 'info'),
        dismiss,
    };
}

export function toastApiError(e) {
    const { extractFieldError } = useFieldErrors();
    const message = extractFieldError(e) || e.message || 'Request failed.';
    add(message, 'error', 6000);
}
