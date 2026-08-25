import { ref, computed } from 'vue'

export function usePagination(items, perPage = 10) {
  const currentPage = ref(1)

  const totalPages = computed(() => Math.max(1, Math.ceil(items.value.length / perPage)))

  const paginatedItems = computed(() => {
    const start = (currentPage.value - 1) * perPage
    return items.value.slice(start, start + perPage)
  })

  function goToPage(page) {
    currentPage.value = Math.max(1, Math.min(page, totalPages.value))
  }

  function nextPage() {
    goToPage(currentPage.value + 1)
  }

  function prevPage() {
    goToPage(currentPage.value - 1)
  }

  function reset() {
    currentPage.value = 1
  }

  return { currentPage, totalPages, paginatedItems, goToPage, nextPage, prevPage, reset }
}
