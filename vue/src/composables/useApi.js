import { ref } from 'vue'
import { apiFetch } from '../services/api.js'

export function useApi() {
  const data = ref(null)
  const loading = ref(false)
  const error = ref(null)

  async function execute(url, options = {}) {
    loading.value = true
    error.value = null
    try {
      const res = await apiFetch(url, options)
      data.value = res.data ?? res
      return res
    } catch (e) {
      error.value = e.message
      throw e
    } finally {
      loading.value = false
    }
  }

  return { data, loading, error, execute }
}
