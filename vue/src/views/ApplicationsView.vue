<template>
  <div class="page-header fade-in-up">
    <div>
      <h1 class="page-title">Applications</h1>
      <p class="page-subtitle">Manage job applications and candidate pipeline</p>
    </div>
  </div>

  <AlertMessage v-if="error" type="danger" :message="error" />

  <section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="panel-header">
      <div>
        <h2>All Applications</h2>
        <p class="panel-desc" v-if="meta.total">{{ meta.total }} total applications</p>
      </div>
      <div class="inline-form">
        <select v-model="statusFilter" @change="loadApplications(1)">
          <option value="">All Statuses</option>
          <option value="new">Pending</option>
          <option value="screening">Under Review</option>
          <option value="shortlisted">Shortlisted</option>
          <option value="interview">Interview</option>
          <option value="offered">Offered</option>
          <option value="hired">Hired</option>
          <option value="rejected">Rejected</option>
        </select>
        <input type="search" v-model="searchQuery" @input="debouncedSearch" placeholder="Search by name, email...">
      </div>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Applicant No.</th>
            <th>Name</th>
            <th>Job</th>
            <th>Email</th>
            <th>Status</th>
            <th>Applied</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="7" class="empty">Loading...</td>
          </tr>
          <tr v-else-if="!applications.length">
            <td colspan="7" class="empty">No applications found.</td>
          </tr>
          <tr v-for="app in applications" :key="app.id">
            <td>{{ app.applicant_no }}</td>
            <td>{{ app.first_name }} {{ app.last_name }}</td>
            <td>{{ app.job_title || '\u2014' }}</td>
            <td>{{ app.email }}</td>
            <td><StatusBadge :status="app.status" /></td>
            <td>{{ formatDate(app.applied_date) }}</td>
            <td class="actions">
              <router-link :to="`/applications/${app.id}`" class="btn btn-sm btn-outline">View</router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationControls
      :current-page="meta.page"
      :total-pages="meta.total_pages"
      @prev="loadApplications(meta.page - 1)"
      @next="loadApplications(meta.page + 1)"
    />
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { apiFetch } from '../services/api.js'
import StatusBadge from '../components/StatusBadge.vue'
import AlertMessage from '../components/AlertMessage.vue'
import PaginationControls from '../components/PaginationControls.vue'

const applications = ref([])
const loading = ref(false)
const error = ref('')
const statusFilter = ref('')
const searchQuery = ref('')
const meta = ref({ page: 1, limit: 10, total: 0, total_pages: 1 })

let searchTimeout = null
function debouncedSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => loadApplications(1), 300)
}

async function loadApplications(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({ page: String(page), limit: '10' })
    if (statusFilter.value) params.set('status', statusFilter.value)
    if (searchQuery.value) params.set('search', searchQuery.value)
    const res = await apiFetch('/api/v1/applications?' + params.toString())
    applications.value = res.data || []
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

onMounted(() => loadApplications(1))
</script>
