<template>
  <!--
    A bounded, internally-scrolling grid instead of letting all 14 hours
    push the whole wp-admin page tall — a full day/week grid routinely
    exceeded the viewport, and since wp-admin's own page height is driven
    by whatever's tallest (often its own left menu, see ByzoSidebar.vue),
    the extra hours just left a very long, mostly-blank page rather than a
    self-contained calendar. Day headers and the hour rail use `sticky`
    *within this same scroll container* (not the page), which is exactly
    the case position:sticky is built for — unlike ByzoSidebar's, this
    container's own height IS the scrollable range, so there's no
    containing-block boundary for it to prematurely un-stick at.
  -->
  <div class="calendar-scroll max-h-[calc(100vh-320px)] min-h-[320px] overflow-auto">
    <div class="flex">
      <!-- Hour rail: pinned to the left edge on horizontal scroll -->
      <div class="sticky left-0 z-20 w-16 shrink-0 border-r border-border bg-card">
        <div class="sticky top-0 z-30 h-10 border-b border-border bg-card"></div>
        <div v-for="hour in hours" :key="hour" class="h-12 whitespace-nowrap border-b border-border/60 px-1.5 text-right text-xs text-muted-foreground">
          {{ formatHour(hour) }}
        </div>
      </div>

      <!-- Day columns -->
      <div
        v-for="day in dayList"
        :key="day.key"
        class="min-w-[110px] flex-1 border-r border-border last:border-r-0"
      >
        <button
          type="button"
          class="sticky top-0 z-10 flex h-10 w-full flex-col items-center justify-center border-b border-border bg-card text-xs hover:bg-muted/50"
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
            :class="{ 'bg-primary/10': dropTarget && dropTarget.dayKey === day.key && dropTarget.hour === hour }"
            :style="{ top: `${(hour - hours[0]) * 48}px`, height: '48px' }"
            @click="$emit('select-slot', { date: day.date, time: hour })"
            @dragover.prevent="onDragOver(day, hour, $event)"
            @dragleave="onDragLeave(day, hour)"
            @drop.prevent="onDrop(day, hour, $event)"
          />

          <!--
            Drag-and-drop reschedule (DETAILED-SPEC.md §3 B3, Phase 2 step
            11) — the SAME unrestricted PUT /bookings/{id}/reschedule the
            detail modal's own slot-picker dropdown already calls
            (BYZO_Booking::reschedule() has never had a capability check),
            just triggered by a drag gesture instead. The parent
            (BookingCalendar.vue) resolves the drop target against a REAL
            generated slot before calling it — dropping on a time with no
            actual open slot fails honestly rather than silently snapping.
          -->
          <button
            v-for="booking in day.bookings"
            :key="booking.id"
            type="button"
            draggable="true"
            class="absolute overflow-hidden rounded px-1.5 py-0.5 text-left text-xs leading-tight text-white shadow-sm ring-1 ring-card"
            :class="{ 'opacity-40': draggingId === booking.id }"
            :style="booking.style"
            :title="`${booking.customer_name} — ${booking.timeLabel}`"
            @click.stop="$emit('select-booking', booking)"
            @dragstart="onDragStart(booking, $event)"
            @dragend="onDragEnd"
          >
            <span class="block truncate font-medium">{{ booking.customer_name }}</span>
            <span v-if="booking.showTime" class="block truncate opacity-90">{{ booking.timeLabel }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { locale } from '@/lib/i18n';
import { ref, computed } from 'vue';
import { addDays, toDateKey, isSameDay, formatTime, formatHourLabel } from '@/lib/date';

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
  days: { type: Number, required: true }, // 1 for day view, 7 for week view
  bookings: { type: Array, required: true },
});
const emit = defineEmits(['select-booking', 'select-slot', 'reschedule-booking']);

// Drag-and-drop reschedule (DETAILED-SPEC.md §3 B3) — only local UI state
// (which booking is mid-drag, which cell it's hovering) lives here; the
// parent resolves the actual target slot and makes the REST call, same
// "dumb presentational grid, smart parent" split every other calendar
// event already follows (select-booking/select-slot).
const draggingId = ref(null);
const dropTarget = ref(null);

function onDragStart(booking, event) {
  draggingId.value = booking.id;
  event.dataTransfer.effectAllowed = 'move';
  // Firefox requires setData to be called for a drag to actually start.
  event.dataTransfer.setData('text/plain', String(booking.id));
}

function onDragEnd() {
  draggingId.value = null;
  dropTarget.value = null;
}

function onDragOver(day, hour) {
  dropTarget.value = { dayKey: day.key, hour };
}

function onDragLeave(day, hour) {
  if (dropTarget.value && dropTarget.value.dayKey === day.key && dropTarget.value.hour === hour) {
    dropTarget.value = null;
  }
}

function onDrop(day, hour, event) {
  const booking = props.bookings.find((b) => b.id === draggingId.value);
  draggingId.value = null;
  dropTarget.value = null;
  if (!booking) {
    return;
  }

  // Snap to the half-hour within the dropped-on hour cell (48px tall,
  // so the top 24px is :00, the bottom 24px is :30) — finer than that
  // isn't meaningfully aimable with a mouse, and the parent still has to
  // match against a REAL generated slot regardless of how precisely this
  // guesses, so a coarser snap here costs nothing.
  const offsetY = event.offsetY ?? 24;
  const minutes = hour * 60 + (offsetY < 24 ? 0 : 30);

  emit('reschedule-booking', { booking, date: day.date, minutes });
}

function formatHour(hour) {
  const d = new Date();
  d.setHours(hour, 0, 0, 0);
  return formatHourLabel(d);
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

// 07:00–21:00 by default covers most small-business hours without forcing
// an all-day 0-23 scroll, but it can't hide anything: the grid stretches
// to the earliest start and latest end among the bookings it's showing
// (QA report 2026-09-22 B11 — a 21:30 table slot and a gala running to
// 23:00 fell off the bottom of a fixed 07–21 grid).
const DEFAULT_FIRST_HOUR = 7;
const DEFAULT_END_HOUR = 21;

// By calendar date, not by instant — the day view's anchor can carry a time.
const bookingsInRange = computed(() => {
  const keys = new Set(Array.from({ length: props.days }, (_, i) => toDateKey(addDays(rangeStart.value, i))));
  return props.bookings.filter((b) => b.slot_start && keys.has(toDateKey(new Date(b.slot_start))));
});

const hours = computed(() => {
  let first = DEFAULT_FIRST_HOUR;
  let end = DEFAULT_END_HOUR;
  for (const b of bookingsInRange.value) {
    const start = new Date(b.slot_start);
    const finish = b.slot_end ? new Date(b.slot_end) : new Date(start.getTime() + 30 * 60000);
    first = Math.min(first, start.getHours());
    // A booking ending after midnight is drawn to the bottom of its own day.
    const endHour = finish.getDate() !== start.getDate() ? 24 : finish.getHours() + (finish.getMinutes() > 0 ? 1 : 0);
    end = Math.max(end, endHour);
  }
  return Array.from({ length: end - first }, (_, i) => i + first);
});

const dayList = computed(() => {
  const today = new Date();
  return Array.from({ length: props.days }, (_, i) => {
    const date = addDays(rangeStart.value, i);
    const key = toDateKey(date);
    const dayBookings = layoutColumns(
      bookingsInRange.value.filter((b) => toDateKey(new Date(b.slot_start)) === key)
    ).map(({ booking, column, columns }) => positionBooking(booking, column, columns));

    return {
      key,
      date,
      isToday: isSameDay(date, today),
      dowLabel: date.toLocaleDateString(locale, { weekday: 'short' }),
      dateLabel: date.getDate(),
      bookings: dayBookings,
    };
  });
});

// Text-xs + leading-tight lines run ~15px each; below this a block can only
// fit the customer-name line without the time line clipping through it
// (previously reported as "booking details not showing properly" — a
// 20-minute-floor booking rendered at just 16px tall, so the time line
// overlapped the name instead of sitting under it).
const TWO_LINE_HEIGHT = 34;
const MIN_BLOCK_HEIGHT = 20;

const PX_PER_MINUTE = 48 / 60;

function visualSpan(booking) {
  const start = new Date(booking.slot_start);
  const end = booking.slot_end ? new Date(booking.slot_end) : new Date(start.getTime() + 30 * 60000);
  const startMinutes = start.getHours() * 60 + start.getMinutes();
  // Short bookings are drawn at MIN_BLOCK_HEIGHT, so that's how much room
  // they really take when deciding what sits next to what.
  const endMinutes = Math.max(startMinutes + MIN_BLOCK_HEIGHT / PX_PER_MINUTE, startMinutes + (end - start) / 60000);
  return { startMinutes, endMinutes };
}

// Bookings that overlap in time share the column width side by side
// instead of stacking on top of each other (QA report 2026-09-22 B11: the
// top block hid the one under it, and a click on it opened the wrong
// booking). Classic calendar packing: sort by start, put each booking in
// the first column whose last booking has ended, and give every booking in
// a chain of overlaps the same column count.
function layoutColumns(dayBookings) {
  const sorted = dayBookings
    .map((booking) => ({ booking, ...visualSpan(booking) }))
    .sort((a, b) => a.startMinutes - b.startMinutes || b.endMinutes - a.endMinutes);

  const placed = [];
  let cluster = [];
  let columnEnds = [];
  let clusterEnd = -1;

  const closeCluster = () => {
    cluster.forEach((entry) => { entry.columns = columnEnds.length; });
    placed.push(...cluster);
    cluster = [];
    columnEnds = [];
  };

  for (const entry of sorted) {
    if (entry.startMinutes >= clusterEnd) {
      closeCluster();
    }
    let column = columnEnds.findIndex((end) => end <= entry.startMinutes);
    if (column === -1) {
      column = columnEnds.length;
      columnEnds.push(entry.endMinutes);
    } else {
      columnEnds[column] = entry.endMinutes;
    }
    entry.column = column;
    cluster.push(entry);
    clusterEnd = Math.max(clusterEnd, entry.endMinutes);
  }
  closeCluster();

  return placed;
}

function positionBooking(booking, column = 0, columns = 1) {
  const start = new Date(booking.slot_start);
  const end = booking.slot_end ? new Date(booking.slot_end) : new Date(start.getTime() + 30 * 60000);

  const dayStartMinutes = hours.value[0] * 60;
  const startMinutes = start.getHours() * 60 + start.getMinutes() - dayStartMinutes;
  const durationMinutes = (end - start) / 60000;

  const pxPerMinute = 48 / 60;
  const rawHeight = durationMinutes * pxPerMinute;

  return {
    ...booking,
    timeLabel: formatTime(start),
    // Short bookings drop the time line rather than clip it; it's still
    // available via the button's `title` tooltip.
    showTime: rawHeight >= TWO_LINE_HEIGHT,
    style: {
      top: `${startMinutes * pxPerMinute}px`,
      height: `${Math.max(MIN_BLOCK_HEIGHT, rawHeight)}px`,
      left: `calc(${(column / columns) * 100}% + 2px)`,
      width: `calc(${100 / columns}% - 4px)`,
      backgroundColor: STATUS_COLORS[booking.status] || STATUS_COLORS.pending,
    },
  };
}
</script>

<style scoped>
/* The grid scrolls both ways inside a rounded Card; an unstyled native
   scrollbar (thick, square) looked out of place against it, especially
   the horizontal one sitting right under the last row. */
.calendar-scroll {
  scrollbar-width: thin;
  scrollbar-color: var(--byzo-border) transparent;
}
.calendar-scroll::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}
.calendar-scroll::-webkit-scrollbar-track {
  background: transparent;
}
.calendar-scroll::-webkit-scrollbar-thumb {
  background-color: var(--byzo-border);
  border-radius: 9999px;
}
.calendar-scroll::-webkit-scrollbar-thumb:hover {
  background-color: var(--byzo-text-muted);
}
</style>
