const BASE = '/HR1/api/v1';

async function request(path, options = {}) {
    const res = await fetch(BASE + path, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', ...(options.headers || {}) },
        ...options
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(data.message || ('Request failed with status ' + res.status));
    }
    return data;
}

export function getOpenJobs() {
    return request('/jobs');
}

export default request;
