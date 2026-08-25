import { auth } from '../stores/auth.js'

export async function apiFetch(url, options = {}) {
  const headers = { ...options.headers }

  if (auth.token.value) {
    headers['Authorization'] = 'Bearer ' + auth.token.value
  }

  if (!(options.body instanceof FormData)) {
    headers['Content-Type'] = headers['Content-Type'] || 'application/json'
  }

  const res = await fetch(url, { ...options, headers })
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
