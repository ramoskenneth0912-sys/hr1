<template>
  <div class="kpi-card fade-in-up" :style="{ animationDelay: delay }">
    <div class="kpi-top">
      <span class="kpi-icon" :class="'kpi-icon-' + color">
        <slot name="icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </slot>
      </span>
    </div>
    <span class="kpi-value">{{ value }}</span>
    <span class="kpi-label">{{ label }}</span>
    <router-link v-if="linkTo" :to="linkTo" class="kpi-link">{{ linkText }} &rarr;</router-link>
    <a v-else-if="href" :href="href" class="kpi-link">{{ linkText }} &rarr;</a>
  </div>
</template>

<script setup>
defineProps({
  value: { type: [Number, String], default: 0 },
  label: { type: String, default: '' },
  color: { type: String, default: 'purple' },
  delay: { type: String, default: '0s' },
  linkTo: { type: [String, Object], default: null },
  href: { type: String, default: null },
  linkText: { type: String, default: 'View' },
})
</script>
