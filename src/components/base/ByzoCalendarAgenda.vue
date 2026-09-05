<template>
  <div class="divide-y divide-border">
    <div v-if="groups.length === 0" class="py-16 text-center text-sm text-muted-foreground">
      No bookings in this range.
    </div>
    <div v-for="group in groups" :key="group.key">
      <div class="sticky top-0 bg-muted/50 px-4 py-1.5 text-xs font-medium text-muted-foreground">
        {{ group.label }}
      </div>
      <button
        v-for="booking in group.bookings"
        :key="booking.id"
        type="button"
        class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-muted/40"
        @click="$emit('select-booking', booking)"
      >
        <span class="w-16 shrink-0 text-muted-foreground">{{ formatTime(new Date(booking.slot_start)) }}</span>
        <span class="flex-1 truncate font-medium">{{ booking.customer_name }}</span>
        <ByzoBadge :status="booking.status">{{ booking.status.replace('_', ' ') }}</ByzoBadge>
      </button>
    </div>
  </div>
</template>

<script setup>
// Agenda view (added 2026-09-05, Phase 1.6, FEATURES.md A4) — a 4th
// calendar mode alongside day/week/month: a flat chronological list,
// useful once a business has more than a handful of bookings on a given
// day and scanning a time grid gets noisy. Reuses the same `bookings`
// prop ByzoCalendarWeek/Month already receive from ByzoCalendar — no
// separate fetch, just a different rendering of the same data.
import { computed } from 'vue';
import ByzoBadge from './ByzoBadge.vue';
import { formatTime, toDateKey } from '@/lib/date';

const props = defineProps({
  bookings: { type: Array, required: true },
});
defineEmits(['select-booking']);

const groups = computed(() => {
  const withTime = props.bookings.filter((b) => b.slot_start);
  const sorted = [...withTime].sort((a, b) => new Date(a.slot_start) - new Date(b.slot_start));

  const byDay = new Map();
  for (const booking of sorted) {
    const key = toDateKey(new Date(booking.slot_start));
    if (!byDay.has(key)) {
      byDay.set(key, []);
    }
    byDay.get(key).push(booking);
  }

  return Array.from(byDay.entries()).map(([key, bookings]) => ({
    key,
    label: new Date(bookings[0].slot_start).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }),
    bookings,
  }));
});
</script>
