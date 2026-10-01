import { describe, expect, it, vi } from 'vitest';
import { ApiError, DEFAULT_API_TIMEOUT_MS } from '@/api/client';

import {
    findActiveAttempt,
    isAmbiguousStartFailure,
    recoverActiveAttempt,
} from '@/utils/examStartRecovery';

describe('exam start recovery', () => {
    it('bounds interactive API waits and classifies timeout errors for recovery', () => {
        expect(DEFAULT_API_TIMEOUT_MS).toBe(30_000);

        const timeout = new ApiError(ApiError.friendly(0), { status: 0, code: 'ECONNABORTED' });
        expect(timeout.isNetwork).toBe(true);
        expect(timeout.isTimeout).toBe(true);
        expect(timeout.message).toContain('server did not respond');
    });

    it('recognizes timeouts and server failures as ambiguous outcomes', () => {
        expect(isAmbiguousStartFailure({ isTimeout: true })).toBe(true);
        expect(isAmbiguousStartFailure({ status: 0, isNetwork: true })).toBe(true);
        expect(isAmbiguousStartFailure({ status: 504 })).toBe(true);
        expect(isAmbiguousStartFailure({ status: 500 })).toBe(true);
        expect(isAmbiguousStartFailure({ status: 422 })).toBe(false);
        expect(isAmbiguousStartFailure({ status: 403 })).toBe(false);
    });

    it('finds only an identified in-progress attempt', () => {
        expect(findActiveAttempt([
            { id: 11, status: 'submitted' },
            { id: 23, status: 'in_progress' },
        ])).toEqual({ id: 23, status: 'in_progress' });
        expect(findActiveAttempt([{ status: 'in_progress' }])).toBeNull();
        expect(findActiveAttempt([{ id: 11, status: 'expired' }])).toBeNull();
    });

    it('checks server state after an ambiguous failure without repeating the start POST', async () => {
        const activeAttempt = { id: 42, status: 'in_progress' };
        const studentApi = { examAttempts: vi.fn().mockResolvedValue([activeAttempt]) };
        const error = { status: 0, isNetwork: true };

        await expect(recoverActiveAttempt(studentApi, 7, error)).resolves.toEqual(activeAttempt);
        expect(studentApi.examAttempts).toHaveBeenCalledWith(7, { timeout: 8_000 });
    });

    it('does not look up attempts for a definite validation or permission failure', async () => {
        const studentApi = { examAttempts: vi.fn() };

        await expect(recoverActiveAttempt(studentApi, 7, { status: 422 })).resolves.toBeNull();
        await expect(recoverActiveAttempt(studentApi, 7, { status: 403 })).resolves.toBeNull();
        expect(studentApi.examAttempts).not.toHaveBeenCalled();
    });

    it('returns safely when the verification request also fails', async () => {
        const studentApi = { examAttempts: vi.fn().mockRejectedValue(new Error('offline')) };

        await expect(recoverActiveAttempt(studentApi, 7, { status: 504 })).resolves.toBeNull();
    });
});
