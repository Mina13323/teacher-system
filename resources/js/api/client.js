import axios from 'axios';

const BASE_URL = import.meta.env.VITE_API_URL || '/api/v1';

/**
 * Normalized, typed error object used across the app. Never expose raw
 * server exception strings to the user; only validated field messages and a
 * friendly status-specific message.
 */
export class ApiError extends Error {
    constructor(message, { status = 0, errors = null, data = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
        this.data = data;
        this.isAuthError = status === 401;
        this.isForbidden = status === 403;
        this.isNotFound = status === 404;
        this.isValidation = status === 422;
        this.isConflict = status === 409;
        this.isRateLimited = status === 429;
        this.isServer = status >= 500;
        this.isNetwork = status === 0;
    }

    /**
     * Human-readable message appropriate to the status code.
     */
    static friendly(status) {
        switch (status) {
            case 401:
                return 'Your session has expired. Please sign in again.';
            case 403:
                return 'You do not have permission to perform this action.';
            case 404:
                return 'The requested resource could not be found.';
            case 409:
                return 'That request conflicts with the current state.';
            case 422:
                return 'Please review the highlighted fields.';
            case 429:
                return 'You are making requests too quickly. Please wait a moment.';
            case 500:
                return 'An unexpected error occurred on the server.';
            default:
                return 'Something went wrong. Please try again.';
        }
    }
}

const client = axios.create({
    baseURL: BASE_URL,
    headers: { Accept: 'application/json' },
});

/**
 * Attach the bearer token (from memory/auth store) to every request.
 * The token is never persisted to localStorage/sessionStorage by this layer.
 */
export function setAuthToken(token) {
    if (token) {
        client.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
        delete client.defaults.headers.common.Authorization;
    }
}

/**
 * Normalize any axios/server response into the app's success/data shape and
 * convert failures into ApiError. Always returns the API `data` payload.
 */
async function request(config) {
    try {
        const response = await client.request(config);
        // Backend envelope: { success, message, data }. Paginated/collection
        // payloads are either in `data` (raw) or the standard Laravel paginator
        // shape when data is an array-like. We return the whole `data` as-is so
        // callers can read `data.x` or iterate a collection, and read the
        // Laravel pagination meta for paged resources.
        const body = response.data;
        return body?.data ?? body;
    } catch (error) {
        let status = error.response?.status ?? 0;
        let errors = null;
        let serverMessage = null;

        if (error.response?.data) {
            const payload = error.response.data;
            serverMessage = payload.message || null;
            errors = payload.errors || null;
        }

        if (error.code === 'ECONNABORTED') {
            status = 0;
        }

        const message =
            errors && status === 422
                ? ApiError.friendly(422)
                : serverMessage || ApiError.friendly(status);

        throw new ApiError(message, { status, errors, data: error.response?.data });
    }
}

export default {
    get: (url, params) => request({ method: 'get', url, params }),
    post: (url, data) => request({ method: 'post', url, data }),
    put: (url, data) => request({ method: 'put', url, data }),
    patch: (url, data) => request({ method: 'patch', url, data }),
    delete: (url, data) => request({ method: 'delete', url, data }),
};
