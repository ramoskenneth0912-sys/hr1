<template>
  <section class="welcome-banner fade-in-up">
    <div class="welcome-content">
      <p class="welcome-greeting">{{ greeting }}, {{ userName }}!</p>
      <h1 class="welcome-title">HR &amp; Administration Workspace</h1>
      <p class="welcome-subtitle">Recruitment, Human Resources &amp; Employee Management</p>
    </div>
    <div class="welcome-status">
      <span class="status-label">System Status</span>
      <span class="status-value"><span class="live-dot"></span> Synced</span>
    </div>
  </section>

  <div class="stats-grid stats-grid-3">
    <StatCard
      :value="stats.jobs?.total ?? 0"
      label="Total Job Postings"
      color="purple"
      delay=".1s"
      link-to="/jobs"
      link-text="View Recruitment"
    >
      <template #icon>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
      </template>
    </StatCard>

    <StatCard
      :value="stats.jobs?.open ?? 0"
      label="Open Positions"
      color="blue"
      delay=".15s"
      link-to="/jobs"
      link-text="View Jobs"
    >
      <template #icon>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </template>
    </StatCard>

    <StatCard
      :value="stats.applications?.total ?? 0"
      label="Total Applicants"
      color="orange"
      delay=".25s"
      link-to="/applications"
      link-text="View Applications"
    >
      <template #icon>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      </template>
    </StatCard>
  </div>

  <div class="content-grid">
    <section class="panel fade-in-up" style="animation-delay:.4s" v-if="recentJobs.length">
      <div class="panel-header">
        <div>
          <h2>Recent Job Postings</h2>
          <p class="panel-desc">Latest positions added to the system</p>
        </div>
        <router-link to="/jobs" class="btn btn-sm btn-outline">View All</router-link>
      </div>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Job Code</th>
              <th>Title</th>
              <th>Department</th>
              <th>Vacancies</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="job in recentJobs" :key="job.id">
              <td>{{ job.job_code }}</td>
              <td>{{ job.title }}</td>
              <td>{{ job.department || '\u2014' }}</td>
              <td>{{ job.vacancies }}</td>
              <td><StatusBadge :status="job.status" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel fade-in-up" style="animation-delay:.5s" v-if="appStatusList.length">
      <div class="panel-header">
        <div>
          <h2>Application Pipeline</h2>
          <p class="panel-desc">Applications by status</p>
        </div>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Count</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in appStatusList" :key="item.key">
            <td><StatusBadge :status="item.key" /></td>
            <td>{{ item.count }}</td>
          </tr>
          <tr v-if="!appStatusList.length">
            <td colspan="2" class="empty">No applications yet.</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { auth } from '../stores/auth.js'
import { apiFetch } from '../services/api.js'
import StatCard from '../components/StatCard.vue'
import StatusBadge from '../components/StatusBadge.vue'

const stats = ref({})
const recentJobs = ref([])
const appStatuses = ref({})

const userName = computed(() => {
  const u = auth.user.value
  if (!u) return ''
  return (u.first_name || u.username || '').trim()
})

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
})

const appStatusList = computed(() => {
  const byStatus = appStatuses.value
  return Object.keys(byStatus).map(key => ({
    key,
    label: byStatus[key].label,
    count: byStatus[key].count,
  }))
})

onMounted(async () => {
  try {
    const [statsRes, jobsRes] = await Promise.all([
      apiFetch('/api/v1/admin/stats'),
      apiFetch('/api/v1/jobs?admin_view=1&limit=5'),
    ])
    stats.value = statsRes.data || {}
    appStatuses.value = statsRes.data?.applications?.by_status || {}
    recentJobs.value = jobsRes.data || []
  } catch (e) {
    console.error('Dashboard load error:', e)
  }
})
</script>
