const BASE = '/HR1/api/v1';

async function jsonRequest(path, options = {}) {
    const res = await fetch(BASE + path, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', ...(options.headers || {}) },
        ...options,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        return { success: false, message: data.message || 'Request failed' };
    }
    return { success: true, data: data.data, message: data.message };
}

/**
 * HR1-backed API service used by the ESS modules.
 *
 * getMyProfile()  is wired to the existing /auth/me endpoint (session-scoped,
 *                 returns the logged-in user's own record only).
 * getMySuccession() has no backend table/API in HR1 yet, so it returns a
 *                 non-success response and the module falls back to its
 *                 "not in succession pipeline" empty state.
 */
export const apiService = {
    async getMyProfile() {
        return jsonRequest('/auth/me');
    },

    async getMySuccession() {
        return { success: false, message: 'Succession data is not available yet.' };
    },
};

export default apiService;