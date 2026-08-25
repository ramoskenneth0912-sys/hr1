<template>
  <div class="page-header fade-in-up">
    <div>
      <h1 class="page-title">Settings</h1>
      <p class="page-subtitle">Your profile and account settings</p>
    </div>
  </div>

  <AlertMessage v-if="error" type="danger" :message="error" />
  <AlertMessage v-if="success" type="success" :message="success" />

  <section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Profile Information</h2>
    <div class="detail-grid" style="margin-top:1rem;">
      <div class="detail-item">
        <label>Username</label>
        <span>{{ user?.username || '\u2014' }}</span>
      </div>
      <div class="detail-item">
        <label>Email</label>
        <span>{{ user?.email || '\u2014' }}</span>
      </div>
      <div class="detail-item">
        <label>Role</label>
        <span>{{ user?.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : '\u2014' }}</span>
      </div>
      <div class="detail-item">
        <label>Account Created</label>
        <span>{{ formatDate(user?.created_at) }}</span>
      </div>
    </div>
  </section>

  <section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>Change Password</h2>
    <div class="form-panel" style="box-shadow:none;padding:0;max-width:100%;margin-top:1rem;">
      <form @submit.prevent="changePassword">
        <div class="form-grid">
          <div class="form-group full-width">
            <label for="current_password">Current Password</label>
            <input id="current_password" type="password" v-model="pwForm.current_password" required autocomplete="current-password">
          </div>
          <div class="form-group">
            <label for="new_password">New Password</label>
            <input id="new_password" type="password" v-model="pwForm.new_password" required minlength="8" autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input id="confirm_password" type="password" v-model="pwForm.confirm_password" required autocomplete="new-password">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="savingPw">
            {{ savingPw ? 'Saving...' : 'Update Password' }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>

<script setup>
import { ref, computed, reactive, onMounted } from 'vue'
import { auth } from '../stores/auth.js'
import { apiFetch } from '../services/api.js'
import AlertMessage from '../components/AlertMessage.vue'

const user = computed(() => auth.user.value)
const error = ref('')
const success = ref('')
const savingPw = ref(false)

const pwForm = reactive({
  current_password: '',
  new_password: '',
  confirm_password: '',
})

function formatDate(dateStr) {
  if (!dateStr) return '\u2014'
  return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

async function changePassword() {
  error.value = ''
  success.value = ''

  if (pwForm.new_password !== pwForm.confirm_password) {
    error.value = 'New password and confirmation do not match.'
    return
  }

  if (pwForm.new_password.length < 8) {
    error.value = 'Password must be at least 8 characters.'
    return
  }

  savingPw.value = true
  try {
    await apiFetch(`/api/v1/users/${user.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        current_password: pwForm.current_password,
        password: pwForm.new_password,
      }),
    })
    success.value = 'Password updated successfully.'
    pwForm.current_password = ''
    pwForm.new_password = ''
    pwForm.confirm_password = ''
  } catch (e) {
    error.value = e.data?.errors ? Object.values(e.data.errors).join('. ') : e.message
  } finally {
    savingPw.value = false
  }
}
</script>
