<template>
  <div class="page-header fade-in-up">
    <div>
      <h1 class="page-title">Recruitment Management</h1>
      <p class="page-subtitle">Job postings and candidate tracking</p>
    </div>
    <div class="btn-group">
      <router-link to="/jobs/create" class="btn btn-primary">+ Job Posting</router-link>
    </div>
  </div>

  <AlertMessage v-if="error" type="danger" :message="error" />

  <section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="panel-header">
      <div>
        <h2>Job Postings</h2>
        <p class="panel-desc" v-if="meta.total">{{ meta.total }} total jobs</p>
      </div>
      <div class="inline-form">
        <select v-model="statusFilter" @change="loadJobs(1)">
          <option value="">All Statuses</option>
          <option value="draft">Draft</option>
          <option value="open">Open</option>
          <option value="closed">Closed</option>
          <option value="filled">Filled</option>
        </select>
        <input type="search" v-model="searchQuery" @input="debouncedSearch" placeholder="Search jobs...">
      </div>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Job Code</th>
            <th>Title</th>
            <th>Department</th>
            <th>Type</th>
            <th>Vacancies</th>
            <th>Status</th>
            <th>Posted</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="8" class="empty">Loading...</td>
          </tr>
          <tr v-else-if="!jobs.length">
            <td colspan="8" class="empty">No job postings found.</td>
          </tr>
          <tr v-for="job in jobs" :key="job.id">
            <td>{{ job.job_code }}</td>
            <td>{{ job.title }}</td>
            <td>{{ job.department || '\u2014' }}</td>
            <td>{{ formatEmploymentType(job.employment_type) }}</td>
            <td>{{ job.vacancies }}</td>
            <td><StatusBadge :status="job.status" /></td>
            <td>{{ formatDate(job.posted_date) }}</td>
            <td class="actions">
              <router-link :to="`/jobs/${job.id}/edit`" class="btn btn-sm btn-outline">Edit</router-link>
              <button class="btn btn-sm btn-danger" @click="confirmDelete(job)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationControls
      :current-page="meta.page"
      :total-pages="meta.total_pages"
      @prev="loadJobs(meta.page - 1)"
      @next="loadJobs(meta.page + 1)"
    />
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { apiFetch } from '../services/api.js'
import StatusBadge from '../components/StatusBadge.vue'
import AlertMessage from '../components/AlertMessage.vue'
import PaginationControls from '../components/PaginationControls.vue'

const jobs = ref([])
const loading = ref(false)
const error = ref('')
const statusFilter = ref('')
const searchQuery = ref('')
const meta = ref({ page: 1, limit: 10, total: 0, total_pages: 1 })

let searchTimeout = null
function debouncedSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => loadJobs(1), 300)
}

async function loadJobs(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({ admin_view: '1', page: String(page), limit: '10' })
    if (statusFilter.value) params.set('status', statusFilter.value)
    if (searchQuery.value) params.set('search', searchQuery.value)
    const res = await apiFetch('/api/v1/jobs?' + params.toString())
    jobs.value = res.data || []
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

function formatEmploymentType(type) {
  const map = { regular: 'Regular', contractual: 'Contractual', probationary: 'Probationary', part_time: 'Part-time', internship: 'Internship' }
  return map[type] || type
}

async function confirmDelete(job) {
  if (!confirm(`Delete "${job.title}" (${job.job_code})? This cannot be undone.`)) return
  try {
    await apiFetch(`/api/v1/jobs/${job.id}`, { method: 'DELETE' })
    loadJobs(meta.value.page)
  } catch (e) {
    error.value = e.message
  }
}

onMounted(() => loadJobs(1))
</script>
