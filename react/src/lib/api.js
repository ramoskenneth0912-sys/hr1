const BASE = '/HR1/api/v1';

// Optional standalone-SPA bearer token (localStorage 'hr1.token'). When set,
// every request carries it; when absent (classic PHP-session island mode) the
// request falls back to same-origin session cookies exactly as before.
function authHeader() {
    try {
        const token = typeof localStorage !== 'undefined' ? localStorage.getItem('hr1.token') : null;
        return token ? { 'Authorization': 'Bearer ' + token } : {};
    } catch {
        return {};
    }
}

async function request(path, options = {}) {
    const res = await fetch(BASE + path, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', ...authHeader(), ...(options.headers || {}) },
        ...options
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const err = new Error(data.message || ('Request failed with status ' + res.status));
        err.status = res.status;
        throw err;
    }
    return data;
}

export function getOpenJobs() {
    return request('/jobs');
}

export default request;
