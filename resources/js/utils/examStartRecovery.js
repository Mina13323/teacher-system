const RECOVERY_LOOKUP_TIMEOUT_MS = 8_000;

/**
 * A failed start response may be ambiguous: the server could have committed the
 * attempt before the connection or gateway timed out. Only verify state with a
 * read request here; never repeat the attempt-creating POST automatically.
 */
export function isAmbiguousStartFailure(error) {
    const status = Number(error?.status ?? 0);

    return Boolean(
        error?.isNetwork
        || error?.isTimeout
        || status === 0
        || status === 408
        || status >= 500
    );
}

export function findActiveAttempt(attempts) {
    const items = Array.isArray(attempts)
        ? attempts
        : Array.isArray(attempts?.data)
            ? attempts.data
            : Array.isArray(attempts?.items)
                ? attempts.items
                : [];

    return items.find((attempt) => (
        attempt?.status === 'in_progress'
        && attempt.id !== null
        && attempt.id !== undefined
    )) || null;
}

/**
 * Recover an attempt only when the start outcome is uncertain. The short GET
 * prevents the recovery check from keeping the UI busy if the server is down.
 */
export async function recoverActiveAttempt(studentApi, examId, error) {
    if (!isAmbiguousStartFailure(error)) return null;

    try {
        return findActiveAttempt(await studentApi.examAttempts(examId, {
            timeout: RECOVERY_LOOKUP_TIMEOUT_MS,
        }));
    } catch {
        return null;
    }
}
