import request from './api.js';

/**
 * Safe ESS list fetcher.
 *
 * Fetches a list resource from the existing HR1 REST API
 * (`/HR1/api/v1/<path>`) using the shared session-authenticated client
 * (same credentials as the rest of the Employee Portal).
 *
 * When the endpoint does not exist yet (no backend table/API), the API
 * returns 404 and we gracefully fall back to an empty list so the module
 * shows its empty state instead of an error page. This keeps the UI
 * functional today and will start loading live data automatically as soon
 * as the matching endpoint is implemented on the HR1 side.
 */
export async function getEssList(path) {
    try {
        const res = await request(path);
        const data = res && res.data;
        return Array.isArray(data) ? data : [];
    } catch (err) {
        // 404 = endpoint not implemented yet — show empty state
        if (err.status === 404) {
            console.warn('[HR1 ESS]', path, 'endpoint not available yet (404)');
            return [];
        }
        // All other errors are real failures — propagate to caller
        console.error('[HR1 ESS]', path, 'request failed:', err.message);
        throw err;
    }
}

/**
 * Safe ESS write helper — returns null on missing/unsupported endpoints so
 * callers can surface a graceful message instead of a raw exception.
 */
export async function essAction(path, payload) {
    try {
        const res = await request(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        return { success: true, data: res.data, message: res.message };
    } catch (err) {
        // 404 = endpoint not implemented yet
        if (err.status === 404) {
            console.warn('[HR1 ESS]', path, 'action endpoint not available yet (404)');
            return { success: false, message: 'This feature is not available yet.' };
        }
        console.error('[HR1 ESS]', path, 'action failed:', err.message);
        throw err;
    }
}

/**
 * Safe ESS patch helper for implemented endpoints.
 *
 * Reuses the shared session-authenticated client (same credentials as the
 * Employee Portal) for partial updates such as progress/status/notes.
 * Real backend errors (401/403/422/500) are propagated so the UI can surface
 * them instead of silently swallowing them.
 */
export async function essUpdate(path, payload, method = 'PATCH') {
    const res = await request(path, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return { success: true, data: res.data, message: res.message };
}

/**
 * Safe ESS single-item fetcher.
 *
 * Fetches one resource (`/HR1/api/v1/<path>`) and returns its `data`
 * object. A 404 (endpoint missing, or record not found / out of scope —
 * the API never confirms existence) resolves to `null` so callers can show
 * an empty state instead of an error page. All other failures propagate.
 */
export async function getEssItem(path) {
    try {
        const res = await request(path);
        const data = res && res.data;
        return data && typeof data === 'object' ? data : null;
    } catch (err) {
        if (err.status === 404) {
            console.warn('[HR1 ESS]', path, 'not found (404)');
            return null;
        }
        console.error('[HR1 ESS]', path, 'request failed:', err.message);
        throw err;
    }
}

export default { getEssList, getEssItem, essAction, essUpdate };