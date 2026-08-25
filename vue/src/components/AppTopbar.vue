<template>
  <header class="topbar">
    <button type="button" class="sidebar-toggle" @click="$emit('toggleSidebar')" aria-label="Open navigation">
      <span class="burger-box" aria-hidden="true"><span></span><span></span><span></span></span>
    </button>

    <div class="topbar-search">
      <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" placeholder="Search modules, employees, applicants..." aria-label="Search">
    </div>

    <div class="topbar-actions">
      <span class="live-badge">
        <span class="live-dot"></span> Live
      </span>

      <div class="user-profile" v-if="user">
        <span class="user-avatar">{{ initials }}</span>
        <div class="user-info">
          <strong>{{ displayName }}</strong>
          <span>{{ userRole }}</span>
        </div>
        <button class="btn btn-sm btn-outline" style="margin-left:.5rem;" @click="handleLogout">Logout</button>
      </div>
    </div>
  </header>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { auth } from '../stores/auth.js'

defineEmits(['toggleSidebar'])
const router = useRouter()

const user = computed(() => auth.user.value)

const displayName = computed(() => {
  if (!user.value) return ''
  const first = user.value.first_name || ''
  const last = user.value.last_name || user.value.username || ''
  return (first + ' ' + last).trim()
})

const initials = computed(() => {
  if (!user.value) return '?'
  const ch = (user.value.first_name || user.value.username || '').charAt(0)
  return ch.toUpperCase()
})

const userRole = computed(() => {
  const r = user.value?.role || ''
  return r.charAt(0).toUpperCase() + r.slice(1)
})

function handleLogout() {
  auth.logout()
  router.push({ name: 'login' })
}
</script>
