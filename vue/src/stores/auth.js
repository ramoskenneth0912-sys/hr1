import { ref, computed } from 'vue'
import { apiFetch } from '../services/api.js'

const user = ref(null)
const token = ref(localStorage.getItem('hr1_token') || null)

export const auth = {
  user,
  token,
  isAuthenticated: computed(() => !!token.value && !!user.value),
  isAdmin: computed(() => user.value && (user.value.role === 'hr' || user.value.role === 'manager')),

  setToken(newToken) {
    token.value = newToken
    if (newToken) {
      localStorage.setItem('hr1_token', newToken)
    } else {
      localStorage.removeItem('hr1_token')
    }
  },

  async login(credential, password) {
    const res = await apiFetch('/api/v1/auth/login', {
      method: 'POST',
      body: JSON.stringify({ credential, password }),
    })
    auth.setToken(res.data.token)
    user.value = res.data.user
    return res.data
  },

  async fetchUser() {
    const res = await apiFetch('/api/v1/auth/me')
    user.value = res.data
  },

  logout() {
    user.value = null
    auth.setToken(null)
  },
}
