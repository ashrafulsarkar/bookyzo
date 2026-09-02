<template>
  <div class="flex overflow-x-auto">
    <!-- Hour rail -->
    <div class="w-14 shrink-0 border-r border-border">
      <div class="h-10 border-b border-border"></div>
      <div v-for="hour in hours" :key="hour" class="h-12 border-b border-border/60 px-1 text-right text-xs text-muted-foreground">
        {{ formatHour(hour) }}
      </div>
    </div>

    <!-- Day columns -->
    <div
      v-for="day in dayList"
      :key="day.key"
      class="min-w-[140px] flex-1 border-r border-border last:border-r-0"
    >
      <button
        type="button"
        class="flex h-10 w-full flex-col items-center justify-center border-b border-border text-xs hover:bg-muted/50"
        @click="$emit('select-slot', { date: day.date, time: null })"
      >
        <span class="font-medium">{{ day.dowLabel }}</span>
        <span :class="day.isToday ? 'font-semibold text-primary' : 'text-muted-foreground'">{{ day.dateLabel }}</span>
      </button>

      <div class="relative">
        <div v-for="hour in hours" :key="hour" class="h-12 border-b border-border/60"></div>

        <button
          v-for="hour in hours"
          :key="'slot-' + hour"
          type="button"
          class="absolute w-full hover:bg-primary/5"
          :style="{ top: `${hour * 48}px`, height: '48px' }"
          @click="$emit('select-slot', { date: day.date, time: hour })"
        />

        <button
          v-for="booking in day.bookings"
          :key="booking.id"
          type="button"
          class="absolute left-0.5 right-0.5 overflow-hidden rounded px-1.5 py-0.5 text-left text-xs text-white shadow-sm"
          :style="booking.style"
          @click.stop="$emit('select-booking', booking)"
        >
          <span class="block truncate font-medium">{{ booking.customer_name }}</span>
          <span class="block truncate opacity-90">{{ booking.timeLabel }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { addDays, toDateKey, isSameDay, formatTime } from '@/lib/date';

const STATUS_COLORS = {
  pending: 'var(--byzo-status-pending)',
  confirmed: 'var(--byzo-status-confirmed)',
  completed: 'var(--byzo-status-completed)',
  cancelled: 'var(--byzo-status-cancelled)',
  no_show: 'var(--byzo-status-no-show)',
};

const props = defineProps({
  anchor: { type: Date, required: true },
  days: { type: Number, required: true }, // 1 for day view, 7 for week view
  bookings: { type: Array, required: true },
});
defineEmits(['select-booking', 'select-slot']);

// 07:00–20:00 covers the vast majority of small-business hours without
// forcing an all-day 0-23 scroll — DETAILED-SPEC.md's business-hours
// defaults (09:00-17:00) sit comfortably inside this window. Revisit if
// Pro's per-staff schedules (Phase 2) push hours outside this range.
const hours = Array.from({ length: 14 }, (_, i) => i + 7);

function formatHour(hour) {
  const d = new Date();
  d.setHours(hour, 0, 0, 0);
  return formatTime(d);
}

const rangeStart = computed(() => {
  if (props.days === 1) {
    return props.anchor;
  }
  const d = new Date(props.anchor);
  d.setDate(d.getDate() - d.getDay());
  d.setHours(0, 0, 0, 0);
  return d;
});

const dayList = computed(() => {
  const today = new Date();
  return Array.from({ length: props.days }, (_, i) => {
    const date = addDays(rangeStart.value, i);
    const key = toDateKey(date);
    const dayBookings = props.bookings
      .filter((b) => b.slot_start && toDateKey(new Date(b.slot_start)) === key)
      .map((b) => positionBooking(b, date));

    return {
      key,
      date,
      isToday: isSameDay(date, today),
      dowLabel: date.toLocaleDateString(undefined, { weekday: 'short' }),
      dateLabel: date.getDate(),
      bookings: dayBookings,
    };
  });
});

function positionBooking(booking, date) {
  const start = new Date(booking.slot_start);
  const end = booking.slot_end ? new Date(booking.slot_end) : new Date(start.getTime() + 30 * 60000);

  const dayStartMinutes = hours[0] * 60;
  const startMinutes = start.getHours() * 60 + start.getMinutes() - dayStartMinutes;
  const durationMinutes = Math.max(20, (end - start) / 60000);

  const pxPerMinute = 48 / 60;

  return {
    ...booking,
    timeLabel: formatTime(start),
    style: {
      top: `${startMinutes * pxPerMinute}px`,
      height: `${durationMinutes * pxPerMinute}px`,
      backgroundColor: STATUS_COLORS[booking.status] || STATUS_COLORS.pending,
    },
  };
}
</script>
