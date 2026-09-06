import { ref, computed, watch } from 'vue';

/**
 * Every admin list screen (Bookable Items, Bookings, Customers) fetches its
 * full working set in one REST call (per_page=100) and filters it in the
 * browser (type/status/date/search filters) — there's no server-side page
 * cursor to drive off of. This is the shared "slice the filtered array into
 * pages of N" layer so every screen's <ByzoPagination> gets the same
 * page/total/pageSize contract instead of each view re-deriving it.
 *
 * @param {import('vue').Ref<any[]>|import('vue').ComputedRef<any[]>} itemsRef - already-filtered items to paginate
 * @param {number} pageSize
 */
export function useClientPagination(itemsRef, pageSize = 10) {
  const page = ref(1);
  const total = computed(() => itemsRef.value.length);
  const totalPages = computed(() => Math.max(1, Math.ceil(total.value / pageSize)));
  const pageStart = computed(() => (page.value - 1) * pageSize);
  const pageItems = computed(() => itemsRef.value.slice(pageStart.value, pageStart.value + pageSize));

  // Filtering (or a reload shrinking the list) can leave `page` pointing
  // past the new last page — snap back rather than showing an empty table.
  watch([itemsRef, totalPages], () => {
    if (page.value > totalPages.value) page.value = totalPages.value;
  });

  function reset() {
    page.value = 1;
  }

  return { page, total, totalPages, pageItems, pageSize, reset };
}
