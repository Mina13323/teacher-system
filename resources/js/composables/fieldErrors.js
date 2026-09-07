/**
 * Helpers for reading Laravel's validation error shape
 * ({ errors: { field: [message] } }) and turning an ApiError into form errors.
 */
export function useFieldErrors() {
    function fieldErrors(err) {
        return (err && err.errors) || {};
    }

    function extractFieldError(err, field = null) {
        const errors = fieldErrors(err);
        if (field) return errors[field]?.[0] || null;
        const first = Object.values(errors)[0];
        return Array.isArray(first) ? first[0] : (first || null);
    }

    return { fieldErrors, extractFieldError };
}
