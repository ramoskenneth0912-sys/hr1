<template>
  <div class="alert" :class="'alert-' + type" v-if="message">
    {{ message }}
  </div>
</template>

<script setup>
defineProps({
  type: { type: String, default: 'danger' },
  message: { type: String, default: '' },
})
</script>
