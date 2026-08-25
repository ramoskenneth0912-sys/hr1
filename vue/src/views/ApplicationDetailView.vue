<template>
  <div class="page-header fade-in-up">
    <div>
      <h1 class="page-title">Application Detail</h1>
      <p class="page-subtitle" v-if="app">{{ app.applicant_no }} &mdash; {{ app.first_name }} {{ app.last_name }}</p>
    </div>
    <router-link to="/applications" class="btn btn-outline">&larr; Back</router-link>
  </div>

  <AlertMessage v-if="error" type="danger" :message="error" />
  <AlertMessage v-if="success" type="success" :message="success" />

  <div v-if="loading" class="panel fade-in-up"><p class="empty">Loading...</p></div>

  <template v-if="app">
    <div class="two-col">
      <section class="panel fade-in-up" style="animation-delay:.1s">
        <h2>Applicant Information</h2>
        <div class="detail-grid">
          <div class="detail-item">
            <label>Applicant No.</label>
            <span>{{ app.applicant_no }}</span>
          </div>
          <div class="detail-item">
            <label>Full Name</label>
            <span>{{ app.first_name }} {{ app.last_name }}</span>
          </div>
          <div class="detail-item">
            <label>Email</label>
            <span>{{ app.email }}</span>
          </div>
          <div class="detail-item">
            <label>Phone</label>
            <span>{{ app.phone || '\u2014' }}</span>
          </div>
          <div class="detail-item full-width">
            <label>Address</label>
            <span>{{ app.address || '\u2014' }}</span>
          </div>
          <div class="detail-item">
            <label>Education</label>
            <span>{{ app.education || '\u2014' }}</span>
          </div>
          <div class="detail-item">
            <label>Skills</label>
            <span>{{ app.skills || '\u2014' }}</span>
          </div>
          <div class="detail-item full-width" v-if="app.work_experience">
            <label>Work Experience</label>
            <span>{{ app.work_experience }}</span>
          </div>
        </div>
      </section>

      <section class="panel fade-in-up" style="animation-delay:.2s">
        <h2>Application Details</h2>
        <div class="detail-grid">
          <div class="detail-item">
            <label>Position Applied</label>
            <span>{{ app.job_title || '\u2014' }}</span>
          </div>
          <div class="detail-item">
            <label>Department</label>
            <span>{{ app.department || '\u2014' }}</span>
          </div>
          <div class="detail-item">
            <label>Applied Date</label>
            <span>{{ formatDate(app.applied_date) }}</span>
          </div>
          <div class="detail-item">
            <label>Resume</label>
            <span v-if="app.resume_file">{{ app.resume_file }}</span>
            <span v-else class="text-muted">No resume uploaded</span>
          </div>
        </div>

        <div v-if="app.cover_letter" class="detail-item full-width" style="margin-top:1rem;">
          <label>Cover Letter / Notes</label>
          <span style="white-space:pre-wrap;">{{ app.cover_letter }}</span>
        </div>
      </section>
    </div>

    <section class="panel fade-in-up" style="animation-delay:.3s">
      <h2>Update Status</h2>
      <div class="inline-form" style="margin-top:.75rem;">
        <select v-model="newStatus">
          <option value="" disabled>Select status...</option>
          <option value="new">Pending</option>
          <option value="screening">Under Review</option>
          <option value="shortlisted">Shortlisted</option>
          <option value="interview">Interview</option>
          <option value="offered">Offered</option>
          <option value="hired">Hired</option>
          <option value="rejected">Rejected</option>
        </select>
        <button class="btn btn-primary btn-sm" @click="updateStatus" :disabled="!newStatus || saving">
          {{ saving ? 'Saving...' : 'Update Status' }}
        </button>
      </div>
    </section>
  </template>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { apiFetch } from '../services/api.js'
import StatusBadge from '../components/StatusBadge.vue'
import AlertMessage from '../components/AlertMessage.vue'

const route = useRoute()
const app = ref(null)
const loading = ref(true)
const error = ref('')
const success = ref('')
const newStatus = ref('')
const saving = ref(false)

onMounted(async () => {
  try {
    const res = await apiFetch(`/api/v1/applications/${route.params.id}`)
    app.value = res.data
    newStatus.value = app.value.status
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})

function formatDate(dateStr) {
  if (!dateStr) return '\u2014'
  return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function updateStatus() {
  if (!newStatus.value || newStatus.value === app.value.status) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    const res = await apiFetch(`/api/v1/admin/applications/${route.params.id}/status`, {
      method: 'PUT',
      body: JSON.stringify({ status: newStatus.value }),
    })
    app.value.status = res.data.status
    success.value = 'Status updated successfully.'
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}
</script>
