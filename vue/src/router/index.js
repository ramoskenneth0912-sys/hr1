import { createRouter, createWebHashHistory } from 'vue-router'
import { auth } from '../stores/auth.js'

import LoginView from '../views/LoginView.vue'
import DashboardView from '../views/DashboardView.vue'
import JobsView from '../views/JobsView.vue'
import JobFormView from '../views/JobFormView.vue'
import ApplicationsView from '../views/ApplicationsView.vue'
import ApplicationDetailView from '../views/ApplicationDetailView.vue'
import UsersView from '../views/UsersView.vue'
import SettingsView from '../views/SettingsView.vue'

const routes = [
  { name: 'login', path: '/login', component: LoginView, meta: { guest: true } },
  { name: 'dashboard', path: '/', component: DashboardView, meta: { requiresAuth: true } },
  { name: 'jobs', path: '/jobs', component: JobsView, meta: { requiresAuth: true } },
  { name: 'jobs-create', path: '/jobs/create', component: JobFormView, meta: { requiresAuth: true } },
  { name: 'jobs-edit', path: '/jobs/:id/edit', component: JobFormView, meta: { requiresAuth: true } },
  { name: 'applications', path: '/applications', component: ApplicationsView, meta: { requiresAuth: true } },
  { name: 'application-detail', path: '/applications/:id', component: ApplicationDetailView, meta: { requiresAuth: true } },
  { name: 'users', path: '/users', component: UsersView, meta: { requiresAuth: true } },
  { name: 'settings', path: '/settings', component: SettingsView, meta: { requiresAuth: true } },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

router.beforeEach((to) => {
  if (to.meta.requiresAuth && !auth.isAuthenticated.value) {
    return { name: 'login' }
  }
  if (to.meta.guest && auth.isAuthenticated.value) {
    return { name: 'dashboard' }
  }
})

export default router
