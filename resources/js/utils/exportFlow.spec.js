import { describe, expect, it, vi } from 'vitest';
import { pollExport, readExportResponse, MAX_CONSECUTIVE_ERRORS } from './exportFlow.js';

const blobOf = (text, type) => new Blob([text], { type });

describe('export responses (P1-G)', () => {
    it('treats a real file as a file', async () => {
        await expect(readExportResponse({ status: 200, contentType: 'text/csv; charset=utf-8', blob: blobOf('a,b\n1,2', 'text/csv') }))
            .resolves.toEqual({ type: 'file' });
    });

    it('never treats a queued JSON status as the file', async () => {
        const body = JSON.stringify({ success: true, message: 'Export queued', data: { export_id: 12, status: 'queued', rows: 900 } });
        await expect(readExportResponse({ status: 202, contentType: 'application/json', blob: blobOf(body, 'application/json') }))
            .resolves.toEqual({ type: 'queued', exportId: 12, rows: 900 });
    });

    it('reports any other JSON answer as an error, not a download', async () => {
        const body = JSON.stringify({ success: false, message: 'Nope' });
        await expect(readExportResponse({ status: 200, contentType: 'application/json', blob: blobOf(body, 'application/json') }))
            .resolves.toEqual({ type: 'error', message: 'Nope' });
        await expect(readExportResponse({ status: 202, contentType: 'application/json', blob: blobOf('{bad', 'application/json') }))
            .resolves.toEqual({ type: 'error', message: '' });
    });
});

describe('export polling (P1-G)', () => {
    const noWait = () => Promise.resolve();

    it('polls with bounded delays until done', async () => {
        const fetchStatus = vi.fn()
            .mockResolvedValueOnce({ status: 'queued' })
            .mockResolvedValueOnce({ status: 'running' })
            .mockResolvedValueOnce({ status: 'done' });
        const wait = vi.fn(noWait);
        const onUpdate = vi.fn();
        await expect(pollExport({ exportId: 3, fetchStatus, wait, onUpdate })).resolves.toEqual({ state: 'done', exportId: 3 });
        expect(wait.mock.calls.map((c) => c[0])).toEqual([2000, 3000, 5000]);
        expect(onUpdate.mock.calls.map((c) => c[0])).toEqual(['queued', 'running']);
    });

    it('stops on failure with the server error', async () => {
        const fetchStatus = vi.fn().mockResolvedValue({ status: 'failed', error: 'Out of memory' });
        await expect(pollExport({ exportId: 3, fetchStatus, wait: noWait })).resolves.toEqual({ state: 'failed', error: 'Out of memory' });
    });

    it('gives up after its time budget when nothing processes the export', async () => {
        const fetchStatus = vi.fn().mockResolvedValue({ status: 'queued' });
        const res = await pollExport({ exportId: 3, fetchStatus, wait: noWait, delays: [1000], budgetMs: 4500 });
        expect(res).toEqual({ state: 'timeout', status: 'queued' });
        expect(fetchStatus).toHaveBeenCalledTimes(4);
    });

    it('stops after repeated network errors, and tolerates a single one', async () => {
        const flaky = vi.fn().mockRejectedValueOnce(new Error('net')).mockResolvedValueOnce({ status: 'done' });
        await expect(pollExport({ exportId: 3, fetchStatus: flaky, wait: noWait })).resolves.toMatchObject({ state: 'done' });

        const down = vi.fn().mockRejectedValue(new Error('down'));
        await expect(pollExport({ exportId: 3, fetchStatus: down, wait: noWait })).resolves.toEqual({ state: 'error', error: 'down' });
        expect(down).toHaveBeenCalledTimes(MAX_CONSECUTIVE_ERRORS);
    });

    it('stops when the screen is left', async () => {
        let cancelled = false;
        const fetchStatus = vi.fn(async () => { cancelled = true; return { status: 'queued' }; });
        await expect(pollExport({ exportId: 3, fetchStatus, wait: noWait, isCancelled: () => cancelled })).resolves.toEqual({ state: 'cancelled' });
        expect(fetchStatus).toHaveBeenCalledTimes(1);
    });
});
