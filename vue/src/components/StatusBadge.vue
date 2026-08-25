<template>
  <span class="badge" :class="badgeClass">{{ displayText }}</span>
</template>

<script setup>
import { computed } from 'vue'

const STATUS_MAP = {
  new: 'badge-new',
  screening: 'badge-info',
  shortlisted: 'badge-primary',
  interview: 'badge-warning',
  offered: 'badge-primary',
  hired: 'badge-success',
  rejected: 'badge-danger',
  draft: 'badge-secondary',
  open: 'badge-success',
  closed: 'badge-danger',
  filled: 'badge-primary',
  pending: 'badge-warning',
  in_progress: 'badge-info',
  completed: 'badge-success',
  passed: 'badge-success',
  failed: 'badge-danger',
  rescheduled: 'badge-info',
  approved: 'badge-success',
  cancelled: 'badge-secondary',
  active: 'badge-success',
  on_leave: 'badge-warning',
  terminated: 'badge-danger',
  resigned: 'badge-secondary',
}

const props = defineProps({
  status: { type: String, required: true },
})

const badgeClass = computed(() => STATUS_MAP[props.status] || 'badge-secondary')
const displayText = computed(() => props.status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()))
</script>
