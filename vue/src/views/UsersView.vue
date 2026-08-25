<template>
  <div class="page-header fade-in-up">
    <div>
      <h1 class="page-title">User Management</h1>
      <p class="page-subtitle">Manage system users and roles</p>
    </div>
  </div>

  <AlertMessage v-if="error" type="danger" :message="error" />

  <section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="panel-header">
      <div>
        <h2>Users</h2>
        <p class="panel-desc" v-if="meta.total">{{ meta.total }} registered users</p>
      </div>
      <div class="inline-form">
        <input type="search" v-model="searchQuery" @input="debouncedSearch" placeholder="Search users...">
      </div>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="empty">Loading...</td>
          </tr>
          <tr v-else-if="!users.length">
            <td colspan="6" class="empty">No users found.</td>
          </tr>
          <tr v-for="u in users" :key="u.id">
            <td>{{ u.id }}</td>
            <td>{{ u.username }}</td>
            <td>{{ u.email }}</td>
            <td><StatusBadge :status="u.role" /></td>
            <td><StatusBadge :status="u.is_active ? 'active' : 'inactive'" /></td>
            <td>{{ formatDate(u.created_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationControls
      :current-page="meta.page"
      :total-pages="meta.total_pages"
      @prev="loadUsers(meta.page - 1)"
      @next="loadUsers(meta.page + 1)"
    />
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { apiFetch } from '../services/api.js'
import StatusBadge from '../components/StatusBadge.vue'
import AlertMessage from '../components/AlertMessage.vue'
import PaginationControls from '../components/PaginationControls.vue'

const users = ref([])
const loading = ref(false)
const error = ref('')
const searchQuery = ref('')
const meta = ref({ page: 1, limit: 10, total: 0, total_pages: 1 })

let searchTimeout = null
function debouncedSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => loadUsers(1), 300)
}

async function loadUsers(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({ page: String(page), limit: '10' })
    if (searchQuery.value) params.set('search', searchQuery.value)
    const res = await apiFetch('/api/v1/users?' + params.toString())
    users.value = res.data || []
    meta.value = res.meta || { page: 1, limit: 10, total: 0, total_pages: 1 }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function formatDate(dateStr) {
  if (!dateStr) return '\u2014'
  return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

onMounted(() => loadUsers(1))
</script>
