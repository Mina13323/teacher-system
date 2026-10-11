/**
 * Result exports: small sheets arrive as the file itself; large ones are
 * queued by the server (202 + JSON {export_id, status}) and must be polled at
 * GET /teacher/exports/{id} until `done` (then downloaded) or `failed`.
 *
 * A queued export only completes if a queue worker runs GenerateResultExportJob;
 * the polling therefore has a time budget and reports `timeout` instead of
 * waiting forever, and nothing that is not the file is ever saved as one.
 */

export const POLL_DELAYS_MS = [2000, 3000, 5000, 8000, 13000, 20000, 30000];
export const POLL_BUDGET_MS = 3 * 60 * 1000;
export const MAX_CONSECUTIVE_ERRORS = 3;

function isJson(contentType) {
    return /application\/(problem\+)?json/i.test(contentType || '');
}

/**
 * Classifies a file-endpoint response. Resolves to
 *   { type: 'file' } | { type: 'queued', exportId, rows } | { type: 'error', message }.
 */
export async function readExportResponse({ status, contentType, blob }) {
    if (!isJson(contentType)) return { type: 'file' };
    let body = null;
    try {
        body = JSON.parse(await blob.text());
    } catch {
        return { type: 'error', message: '' };
    }
    const data = body?.data;
    if (status === 202 && data?.export_id) {
        return { type: 'queued', exportId: data.export_id, rows: data.rows ?? null };
    }
    return { type: 'error', message: body?.message || '' };
}

/**
 * Polls an export until a terminal state. Resolves to one of
 *   { state: 'done', exportId } | { state: 'failed', error } |
 *   { state: 'timeout', status } | { state: 'error', error } | { state: 'cancelled' }.
 * `onUpdate(status)` reports queued / running while waiting.
 */
export async function pollExport({
    exportId,
    fetchStatus,
    wait = (ms) => new Promise((r) => setTimeout(r, ms)),
    isCancelled = () => false,
    onUpdate = () => {},
    delays = POLL_DELAYS_MS,
    budgetMs = POLL_BUDGET_MS,
}) {
    let waited = 0;
    let errors = 0;
    let lastStatus = 'queued';
    for (let i = 0; ; i++) {
        const delay = delays[Math.min(i, delays.length - 1)];
        if (waited + delay > budgetMs) return { state: 'timeout', status: lastStatus };
        await wait(delay);
        waited += delay;
        if (isCancelled()) return { state: 'cancelled' };
        try {
            const res = await fetchStatus(exportId);
            errors = 0;
            lastStatus = res?.status ?? lastStatus;
            if (lastStatus === 'done') return { state: 'done', exportId };
            if (lastStatus === 'failed') return { state: 'failed', error: res?.error ?? '' };
            onUpdate(lastStatus);
        } catch (e) {
            errors += 1;
            if (errors >= MAX_CONSECUTIVE_ERRORS) return { state: 'error', error: e?.message ?? '' };
        }
        if (isCancelled()) return { state: 'cancelled' };
    }
}
