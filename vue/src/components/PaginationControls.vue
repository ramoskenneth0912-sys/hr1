<template>
  <div class="pagination" v-if="totalPages > 1">
    <button class="btn btn-sm btn-outline" :disabled="currentPage <= 1" @click="$emit('prev')">
      &laquo; Prev
    </button>
    <span class="pagination-info">Page {{ currentPage }} of {{ totalPages }}</span>
    <button class="btn btn-sm btn-outline" :disabled="currentPage >= totalPages" @click="$emit('next')">
      Next &raquo;
    </button>
  </div>
</template>

<script setup>
defineProps({
  currentPage: { type: Number, required: true },
  totalPages: { type: Number, required: true },
})
defineEmits(['prev', 'next'])
</script>

<style scoped>
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  padding: 1rem 0;
}
.pagination-info {
  font-size: .8rem;
  color: var(--muted);
  font-weight: 500;
}
</style>
