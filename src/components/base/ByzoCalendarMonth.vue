<template>
  <div class="grid grid-cols-7 border-b border-l border-border">
    <div v-for="label in dowLabels" :key="label" class="border-r border-t border-border bg-muted/50 px-2 py-1.5 text-center text-xs font-medium text-muted-foreground">
      {{ label }}
    </div>

    <button
      v-for="day in days"
      :key="day.key"
      type="button"
      class="flex min-h-[100px] flex-col items-start gap-1 border-r border-t border-border p-1.5 text-left align-top hover:bg-muted/30"
      :class="!day.inMonth && 'bg-muted/20'"
      @click="$emit('select-day', day.date)"
    >
      <span
        class="flex h-6 w-6 items-center justify-center rounded-full text-xs"
        :class="[day.isToday ? 'bg-primary text-primary-foreground font-semibold' : 'text-muted-foreground', !day.inMonth && 'opacity-40']"
      >
        {{ day.date.getDate() }}
      </span>

      <div class="flex w-full flex-col gap-0.5">
        <button
          v-for="booking in day.bookings.slice(0, 3)"
          :key="booking.id"
          type="button"
          class="w-full truncate rounded px-1 py-0.5 text-left text-[11px] text-white"
          :style="{ backgroundColor: booking.color }"
          @click.stop="$emit('select-booking', booking)"
        >
          {{ booking.timeLabel }} {{ booking.customer_name }}
        </button>
        <span v-if="day.bookings.length > 3" class="px-1 text-[11px] text-muted-foreground">
          {{ sprintf(__('+%s more', 'bookyzo'), day.bookings.length - 3) }}
        </span>
      </div>
    </button>
  </div>
</template>

<script setup>
import { weekdayNames } from '@/lib/date';
import { computed } from 'vue';
import { monthGridDays, toDateKey, isSameDay, formatTime } from '@/lib/date';

const STATUS_COLORS = {
  pending: 'var(--byzo-status-pending)',
  confirmed: 'var(--byzo-status-confirmed)',
  completed: 'var(--byzo-status-completed)',
  cancelled: 'var(--byzo-status-cancelled)',
  no_show: 'var(--byzo-status-no-show)',
  rejected: 'var(--byzo-status-rejected)',
};

const props = defineProps({
  anchor: { type: Date, required: true },
  bookings: { type: Array, required: true },
});
defineEmits(['select-booking', 'select-day']);

const dowLabels = weekdayNames('short');

const days = computed(() => {
  const today = new Date();
  const gridDays = monthGridDays(props.anchor);

  return gridDays.map((date) => {
    const key = toDateKey(date);
    const dayBookings = props.bookings
      .filter((b) => b.slot_start && toDateKey(new Date(b.slot_start)) === key)
      .sort((a, b) => new Date(a.slot_start) - new Date(b.slot_start))
      .map((b) => ({
        ...b,
        timeLabel: formatTime(new Date(b.slot_start)),
        color: STATUS_COLORS[b.status] || STATUS_COLORS.pending,
      }));

    return {
      key,
      date,
      inMonth: date.getMonth() === props.anchor.getMonth(),
      isToday: isSameDay(date, today),
      bookings: dayBookings,
    };
  });
});
</script>
