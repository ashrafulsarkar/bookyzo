<template>
  <div v-if="totalPages > 1" class="flex items-center justify-between border-t px-4 py-3">
    <p class="text-sm text-muted-foreground">{{ sprintf(/*! translators: 1: first row shown, 2: last row shown, 3: total number of rows. */ __('Showing %1$s–%2$s of %3$s', 'bookyzo'), rangeStart, rangeEnd, total) }}</p>
    <div class="flex items-center gap-1">
      <Button variant="outline" size="icon" class="h-8 w-8" :disabled="page === 1" @click="$emit('update:page', page - 1)">
        <ChevronLeft class="h-4 w-4" />
      </Button>
      <span class="px-2 text-sm text-muted-foreground">{{ sprintf(/*! translators: 1: current page number, 2: total number of pages. */ __('Page %1$s of %2$s', 'bookyzo'), page, totalPages) }}</span>
      <Button variant="outline" size="icon" class="h-8 w-8" :disabled="page === totalPages" @click="$emit('update:page', page + 1)">
        <ChevronRight class="h-4 w-4" />
      </Button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

// The one pagination footer for every admin list table (Bookable Items,
// Bookings, Customers, and any future one) — pairs with the
// useClientPagination composable, which does the actual page/pageItems
// slicing. This component only renders the "Showing X–Y of Z" + Prev/Next
// UI and hides itself when everything fits on one page.
const props = defineProps({
  page: { type: Number, required: true },
  totalPages: { type: Number, required: true },
  total: { type: Number, required: true },
  pageSize: { type: Number, required: true },
});

defineEmits(['update:page']);

const rangeStart = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.pageSize + 1));
const rangeEnd = computed(() => Math.min(props.page * props.pageSize, props.total));
</script>
