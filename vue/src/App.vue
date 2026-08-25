<template>
  <div v-if="isLoginPage" class="login-page-wrapper">
    <router-view />
  </div>
  <div v-else-if="isAuthenticated" class="app-layout has-sidebar">
    <AppSidebar />
    <div class="sidebar-overlay" :class="{ visible: sidebarOpen }" @click="sidebarOpen = false"></div>
    <div class="main-wrapper">
      <AppTopbar @toggle-sidebar="sidebarOpen = !sidebarOpen" />
      <main class="container">
        <router-view />
      </main>
    </div>
  </div>
  <div v-else class="login-page-wrapper">
    <router-view />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { auth } from './stores/auth.js'
import AppSidebar from './components/AppSidebar.vue'
import AppTopbar from './components/AppTopbar.vue'

const route = useRoute()
const router = useRouter()
const sidebarOpen = ref(false)

const isLoginPage = computed(() => route.name === 'login')
const isAuthenticated = computed(() => auth.isAuthenticated.value)

router.afterEach(() => {
  sidebarOpen.value = false
})

onMounted(async () => {
  const token = localStorage.getItem('hr1_token')
  if (token) {
    auth.setToken(token)
    try {
      await auth.fetchUser()
    } catch {
      auth.logout()
      router.push({ name: 'login' })
    }
  }
})
</script>

<style>
.login-page-wrapper {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
