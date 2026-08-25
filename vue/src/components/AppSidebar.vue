<template>
  <aside class="sidebar" :class="{ open: open }">
    <div class="sidebar-brand">
      <router-link to="/">
        <img class="brand-logo" :src="brandLogoUrl" alt="Tri-M Global logo">
        <span class="brand-names">
          <span class="brand-name">Tri-M Global</span>
          <span class="brand-sub">Logistics &amp; Trading Inc.</span>
        </span>
      </router-link>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section">
        <span class="nav-section-label">Main</span>
        <router-link to="/" class="nav-link" active-class="exact-active" :class="{ active: isActive('/') }">
          <span class="nav-icon nav-icon-grid"></span>
          <span>Dashboard</span>
        </router-link>
      </div>

      <div class="nav-section" v-if="isAdmin">
        <span class="nav-section-label">Recruitment &amp; HR</span>
        <router-link to="/jobs" class="nav-link" active-class="active">
          <span class="nav-icon nav-icon-briefcase"></span>
          <span>Recruitment</span>
        </router-link>
        <router-link to="/applications" class="nav-link" active-class="active">
          <span class="nav-icon nav-icon-checklist"></span>
          <span>Applications</span>
        </router-link>
      </div>

      <div class="nav-section" v-if="isAdmin">
        <span class="nav-section-label">System</span>
        <router-link to="/users" class="nav-link" active-class="active">
          <span class="nav-icon nav-icon-users"></span>
          <span>User Management</span>
        </router-link>
        <router-link to="/settings" class="nav-link" active-class="active">
          <span class="nav-icon nav-icon-settings"></span>
          <span>Settings</span>
        </router-link>
      </div>
    </nav>

    <div class="sidebar-footer">
      <span class="sidebar-version">HR Admin · Vue v1.0</span>
    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { auth } from '../stores/auth.js'

defineProps({ open: Boolean })
const route = useRoute()
const isAdmin = computed(() => auth.isAdmin.value)
const brandLogoUrl = '/HR1/assets/images/dashboard%20logo.png'

function isActive(path) {
  return route.path === path
}
</script>
