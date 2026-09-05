<template>
  <div class="flex flex-col">
    <div class="flex items-center justify-between border-b border-border px-4 py-3">
      <div class="flex items-center gap-2">
        <Button variant="outline" size="sm" @click="goToday">Today</Button>
        <Button variant="ghost" size="icon" @click="step(-1)" aria-label="Previous">‹</Button>
        <Button variant="ghost" size="icon" @click="step(1)" aria-label="Next">›</Button>
        <span class="ml-2 text-sm font-medium">{{ rangeLabel }}</span>
      </div>
      <div class="flex gap-1 rounded-md border border-border p-0.5">
        <button
          v-for="mode in ['day', 'week', 'month', 'agenda']"
          :key="mode"
          type="button"
          class="rounded px-3 py-1 text-sm capitalize transition-colors"
          :class="viewMode === mode ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'"
          @click="viewMode = mode"
        >
          {{ mode }}
        </button>
      </div>
    </div>

    <ByzoCalendarMonth
      v-if="viewMode === 'month'"
      :anchor="anchor"
      :bookings="bookings"
      @select-booking="(b) => $emit('select-booking', b)"
      @select-day="goToDay"
    />
    <ByzoCalendarAgenda
      v-else-if="viewMode === 'agenda'"
      :bookings="bookings"
      @select-booking="(b) => $emit('select-booking', b)"
    />
    <ByzoCalendarWeek
      v-else
      :anchor="anchor"
      :days="viewMode === 'day' ? 1 : 7"
      :bookings="bookings"
      @select-booking="(b) => $emit('select-booking', b)"
      @select-slot="(payload) => $emit('select-slot', payload)"
    />
  </div>
</template>

<script setup>
// The single most load-bearing custom component in the admin app
// (bookyzo-design skill) — no shadcn-vue equivalent exists (its own
// Calendar/RangeCalendar are date-pickers, not event-grid views). Pro's
// drag-and-drop reschedule (FEATURES.md §B3) builds directly on the
// visual language established here, so the event-block/status-color/
// time-grid-line conventions in ByzoCalendarWeek/Month are the ones to
// keep consistent as the app grows, not reinvent per-screen.
import { ref, computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import ByzoCalendarWeek from './ByzoCalendarWeek.vue';
import ByzoCalendarMonth from './ByzoCalendarMonth.vue';
import ByzoCalendarAgenda from './ByzoCalendarAgenda.vue';
import { startOfWeek, addDays, formatDayLabel, formatMonthLabel } from '@/lib/date';

const props = defineProps({
  bookings: { type: Array, required: true },
});
const emit = defineEmits(['range-change', 'select-booking', 'select-slot']);

const viewMode = ref('week');
const anchor = ref(new Date());

const rangeLabel = computed(() => {
  if (viewMode.value === 'month') {
    return formatMonthLabel(anchor.value);
  }
  if (viewMode.value === 'day') {
    return formatDayLabel(anchor.value);
  }
  // week AND agenda (added 2026-09-05, Phase 1.6) share the same 7-day
  // window — agenda is a flat re-render of the same range, not a new
  // range concept of its own.
  const start = startOfWeek(anchor.value);
  const end = addDays(start, 6);
  return `${formatDayLabel(start)} – ${formatDayLabel(end)}`;
});

function step(direction) {
  if (viewMode.value === 'month') {
    anchor.value = new Date(anchor.value.getFullYear(), anchor.value.getMonth() + direction, 1);
  } else if (viewMode.value === 'day') {
    anchor.value = addDays(anchor.value, direction);
  } else {
    anchor.value = addDays(anchor.value, direction * 7);
  }
}

function goToday() {
  anchor.value = new Date();
}

function goToDay(date) {
  anchor.value = date;
  viewMode.value = 'day';
}

// Parent owns fetching — this component just tells it what range is
// currently visible, so a single `bookings` prop stays the source of
// truth rather than each sub-view fetching independently.
watch(
  [viewMode, anchor],
  () => {
    let from;
    let to;
    if (viewMode.value === 'month') {
      from = new Date(anchor.value.getFullYear(), anchor.value.getMonth() - 1, 21);
      to = new Date(anchor.value.getFullYear(), anchor.value.getMonth() + 2, 10);
    } else if (viewMode.value === 'day') {
      from = anchor.value;
      to = anchor.value;
    } else {
      from = startOfWeek(anchor.value);
      to = addDays(from, 6);
    }
    emit('range-change', { from, to });
  },
  { immediate: true }
);
</script>
