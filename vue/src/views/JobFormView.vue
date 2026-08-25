<template>
  <div class="page-header fade-in-up">
    <div>
      <h1 class="page-title">{{ isEdit ? 'Edit Job Posting' : 'Create Job Posting' }}</h1>
      <p class="page-subtitle">{{ isEdit ? 'Update the job details below' : 'Fill in the details for a new position' }}</p>
    </div>
    <router-link to="/jobs" class="btn btn-outline">&larr; Back to Jobs</router-link>
  </div>

  <AlertMessage v-if="error" type="danger" :message="error" />
  <AlertMessage v-if="success" type="success" :message="success" />

  <div class="form-panel fade-in-up" style="animation-delay:.1s">
    <form @submit.prevent="handleSubmit">
      <div class="form-grid">
        <div class="form-group">
          <label for="title">Job Title *</label>
          <input id="title" v-model="form.title" required placeholder="e.g. Senior Accountant">
        </div>

        <div class="form-group">
          <label for="department_id">Department</label>
          <select id="department_id" v-model="form.department_id">
            <option value="">No Department</option>
            <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
          </select>
        </div>

        <div class="form-group">
          <label for="employment_type">Employment Type</label>
          <select id="employment_type" v-model="form.employment_type">
            <option value="regular">Regular</option>
            <option value="contractual">Contractual</option>
            <option value="probationary">Probationary</option>
            <option value="part_time">Part-time</option>
            <option value="internship">Internship</option>
          </select>
        </div>

        <div class="form-group">
          <label for="vacancies">Vacancies</label>
          <input id="vacancies" type="number" v-model.number="form.vacancies" min="0" placeholder="1">
        </div>

        <div class="form-group">
          <label for="status">Status</label>
          <select id="status" v-model="form.status">
            <option value="draft">Draft</option>
            <option value="open">Open</option>
            <option value="closed">Closed</option>
            <option value="filled">Filled</option>
          </select>
        </div>

        <div class="form-group">
          <label for="location">Work Location</label>
          <input id="location" v-model="form.location" placeholder="e.g. Manila, Philippines">
        </div>

        <div class="form-group">
          <label for="posted_date">Posted Date</label>
          <input id="posted_date" type="date" v-model="form.posted_date">
        </div>

        <div class="form-group">
          <label for="closing_date">Closing Date</label>
          <input id="closing_date" type="date" v-model="form.closing_date">
        </div>

        <div class="form-group full-width">
          <label for="description">Description</label>
          <textarea id="description" v-model="form.description" rows="4" placeholder="Describe the role and responsibilities..."></textarea>
        </div>

        <div class="form-group full-width">
          <label for="requirements">Requirements</label>
          <textarea id="requirements" v-model="form.requirements" rows="3" placeholder="Required qualifications and skills..."></textarea>
        </div>

        <div class="form-group full-width">
          <label for="qualifications">Qualifications</label>
          <textarea id="qualifications" v-model="form.qualifications" rows="3" placeholder="Education and experience requirements..."></textarea>
        </div>

        <div class="form-group full-width">
          <label for="required_skills">Required Skills</label>
          <textarea id="required_skills" v-model="form.required_skills" rows="2" placeholder="Comma-separated list of skills..."></textarea>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary" :disabled="saving">
          {{ saving ? 'Saving...' : (isEdit ? 'Update Job' : 'Create Job') }}
        </button>
        <router-link to="/jobs" class="btn btn-outline">Cancel</router-link>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch } from '../services/api.js'
import AlertMessage from '../components/AlertMessage.vue'

const route = useRoute()
const router = useRouter()
const isEdit = computed(() => !!route.params.id)

const form = reactive({
  title: '',
  department_id: '',
  description: '',
  requirements: '',
  qualifications: '',
  required_skills: '',
  education_requirement: '',
  experience_requirement: '',
  location: '',
  employment_type: 'regular',
  vacancies: 1,
  status: 'draft',
  posted_date: '',
  closing_date: '',
})

const departments = ref([])
const saving = ref(false)
const error = ref('')
const success = ref('')

onMounted(async () => {
  try {
    const deptRes = await apiFetch('/api/v1/departments')
    departments.value = deptRes.data ?? []
  } catch {}

  if (isEdit.value) {
    try {
      const res = await apiFetch(`/api/v1/jobs/${route.params.id}`)
      const job = res.data
      form.title = job.title || ''
      form.department_id = job.department_id || ''
      form.description = job.description || ''
      form.requirements = job.requirements || ''
      form.qualifications = job.qualifications || ''
      form.required_skills = job.required_skills || ''
      form.education_requirement = job.education_requirement || ''
      form.experience_requirement = job.experience_requirement || ''
      form.location = job.location || ''
      form.employment_type = job.employment_type || 'regular'
      form.vacancies = job.vacancies ?? 1
      form.status = job.status || 'draft'
      form.posted_date = job.posted_date || ''
      form.closing_date = job.closing_date || ''
    } catch (e) {
      error.value = e.message
    }
  }
})

async function handleSubmit() {
  saving.value = true
  error.value = ''
  success.value = ''

  const payload = { ...form }
  if (!payload.department_id) payload.department_id = null
  if (!payload.posted_date) payload.posted_date = null
  if (!payload.closing_date) payload.closing_date = null

  try {
    if (isEdit.value) {
      await apiFetch(`/api/v1/jobs/${route.params.id}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      success.value = 'Job updated successfully.'
    } else {
      const res = await apiFetch('/api/v1/jobs', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      router.push({ name: 'jobs' })
      return
    }
  } catch (e) {
    error.value = e.data?.errors ? Object.values(e.data.errors).join('. ') : e.message
  } finally {
    saving.value = false
  }
}
</script>
