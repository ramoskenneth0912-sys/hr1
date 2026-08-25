<template>
  <div class="auth-shell">
    <section class="panel-brand">
      <span class="brand-shape s1" aria-hidden="true"></span>
      <span class="brand-shape s2" aria-hidden="true"></span>
      <span class="brand-shape s3" aria-hidden="true"></span>
      <span class="brand-shape s4" aria-hidden="true"></span>
      <span class="brand-line" aria-hidden="true"></span>
      <div class="brand-content">
        <div class="brand-copy">
          <h1>Welcome Back</h1>
          <p class="lead">Access the TRI-M GLOBAL Merchandising Management System.</p>
          <p class="desc">Manage employee records, recruitment, and HR operations through a secure and centralized system.</p>
        </div>
        <span class="brand-tag">Building connections. Delivering excellence.</span>
      </div>
    </section>

    <main class="panel-form">
      <div class="form-side">
        <img :src="loginLogoUrl" alt="TRI-M GLOBAL" class="login-logo">
        <h2>Sign in to your account</h2>
        <span class="portal-badge">HR / Manager and Employee Portal</span>

        <AlertMessage v-if="errorMsg" type="danger" :message="errorMsg" />

        <div class="login-inner">
          <form @submit.prevent="handleLogin">
            <div class="form-group">
              <label for="credential">Email Address</label>
              <div class="input-wrap">
                <input type="text" id="credential" v-model="credential" required autofocus placeholder="Enter your email" autocomplete="username">
              </div>
            </div>

            <div class="form-group">
              <label for="password">Password</label>
              <div class="input-wrap">
                <input :type="showPw ? 'text' : 'password'" id="password" v-model="password" required placeholder="Enter your password" autocomplete="current-password" class="has-trailing">
                <button type="button" class="pw-toggle" :class="{ active: showPw }" @click="showPw = !showPw" :aria-label="showPw ? 'Hide password' : 'Show password'">
                  <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
            </div>

            <button type="submit" class="btn btn-primary" :disabled="loading">
              {{ loading ? 'Signing in...' : 'Sign In' }}
            </button>
          </form>
        </div>

        <p class="login-footnote">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Authorized access for HR Managers and Employees.
        </p>

        <a href="/public/jobs.php" class="login-back">&larr; Back to Public Site</a>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { auth } from '../stores/auth.js'
import AlertMessage from '../components/AlertMessage.vue'

const router = useRouter()
const loginLogoUrl = '/HR1/assets/images/login%20logo.jpg'
const credential = ref('')
const password = ref('')
const showPw = ref(false)
const loading = ref(false)
const errorMsg = ref('')

async function handleLogin() {
  loading.value = true
  errorMsg.value = ''
  try {
    await auth.login(credential.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (e) {
    errorMsg.value = e.message || 'Invalid username/email or password.'
  } finally {
    loading.value = false
  }
}
</script>
