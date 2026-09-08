import { auth } from '../stores/auth.js'

export const API_BASE = '/HR1/api/v1'

export async function apiFetch(url, options = {}) {
  const headers = { ...options.headers }

  if (auth.token.value) {
    headers['Authorization'] = 'Bearer ' + auth.token.value
  }

  if (!(options.body instanceof FormData)) {
    headers['Content-Type'] = headers['Content-Type'] || 'application/json'
  }

  const res = await fetch(API_BASE + url, {
    ...options,
    headers,
    credentials: 'same-origin',
  })
  const data = await res.json().catch(() => null)

  if (res.status === 401) {
    auth.logout()
    window.location.hash = '#/login'
    throw new Error('Unauthorized')
  }

  if (!res.ok) {
    const err = new Error(data?.message || 'Request failed')
    err.status = res.status
    err.data = data
    throw err
  }

  return data
}
