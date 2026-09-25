<template>
  <div>
    <h2>{{ copy.slotHeading }}</h2>
    <p class="step-sub">{{ item.name }}</p>

    <WidgetLoading v-if="loading" />
    <div v-else-if="error" class="error">{{ error }}</div>

    <template v-else>
      <!-- Party size is asked BEFORE times for Table (OpenTable's order,
           and the only genuinely structural per-type difference in this
           flow): a table for six either exists at 7pm or it doesn't, so
           showing every time first and only then discovering the party
           doesn't fit wastes the visitor's main decision. The other types
           ask on the details step, where quantity doesn't change which
           slots are bookable. -->
      <div v-if="asksPartySizeFirst" class="party-size">
        <label for="bw-slot-party">{{ copy.quantityLabel }}</label>
        <select id="bw-slot-party" v-model.number="partySize">
          <option v-for="n in partySizeOptions" :key="n" :value="n">
            {{ n }}
          </option>
        </select>
      </div>

      <div v-if="dates.length === 0" class="empty">
        {{ emptyMessage }}
      </div>

      <!-- An event is a set of specific dated occurrences the admin
           entered by hand (DETAILED-SPEC.md §1.3), not a recurring
           pattern — so there is no "pick a day, then a time within it" to
           do. A month calendar would render mostly-empty months to
           navigate for what is usually a handful of dates, so events get
           the occurrences directly as one list. -->
      <div v-else-if="isEvent" class="occurrence-list">
        <button
          v-for="slot in slots"
          :key="slot.id"
          type="button"
          class="option-card"
          :class="{ 'option-card--selected': selectedSlotId === slot.id, 'option-card--full': isFull(slot) }"
          :disabled="isSoldOut(slot) && !isFull(slot)"
          @click="selectedSlotId = slot.id"
        >
          <span class="option-card-body">
            <span class="name">{{ formatOccurrenceDate(slot.start_datetime) }}</span>
            <span class="meta">
              <span class="meta-item">{{ formatTime(slot.start_datetime) }}</span>
              <span v-if="seatsLabel(slot)" class="meta-item">{{ seatsLabel(slot) }}</span>
            </span>
          </span>
        </button>
      </div>

      <template v-else>
      <!-- A month grid rather than a flat run of date chips: with a 30-day
           booking window the old list rendered ~26 chips with no sense of
           week, weekend, or month boundary. A calendar is the shape people
           already read dates in, and it makes "nothing free this week"
           visible at a glance instead of requiring a scan. -->
      <!-- Calendar and times sit side by side when there's room: the
           calendar has a fixed natural width, so a wide embed otherwise
           left a large empty column beside it while the times stacked
           underneath. Wraps to one column on narrow screens. -->
      <div class="picker">
        <div class="cal">
          <div class="cal-head">
          <button
            type="button"
            class="cal-nav"
            :disabled="!canGoPrev"
            :aria-label="__('Previous month', 'bookyzo')"
            @click="shiftMonth(-1)"
          >
            &#8249;
          </button>
            <span class="cal-title">{{ monthLabel }}</span>
            <button
              type="button"
              class="cal-nav"
              :disabled="!canGoNext"
              :aria-label="__('Next month', 'bookyzo')"
              @click="shiftMonth(1)"
            >
              &#8250;
            </button>
          </div>

          <div class="cal-grid cal-dow">
            <span v-for="dow in dayNames" :key="dow">{{ dow }}</span>
          </div>

          <div class="cal-grid">
            <template v-for="(cell, index) in monthCells" :key="index">
              <span v-if="!cell" class="cal-cell cal-cell--empty" />
              <button
                v-else
                type="button"
                class="cal-cell"
                :class="{
                  'cal-cell--open': cell.available,
                  'cal-cell--full': cell.available && cell.fullOnly,
                  'cal-cell--selected': cell.key === selectedDateKey,
                }"
                :title="cell.available && cell.fullOnly ? __('Full — waitlist only', 'bookyzo') : undefined"
                :disabled="!cell.available"
                @click="selectedDateKey = cell.key"
              >
                {{ cell.day }}
              </button>
            </template>
          </div>
        </div>

        <!-- Times grouped by part of day. A single flat row of 17 times
             reads as noise; morning/afternoon/evening is how people
             actually decide, and the headings give the eye somewhere to
             land. Empty groups are omitted, never rendered as blank. -->
        <div v-if="selectedDateKey" class="times">
          <div class="times-head">{{ selectedDateLabel }}</div>
          <!-- Only the groups scroll; the date heading stays put so it's
               still visible while scanning a long list of times. -->
          <div ref="scrollEl" class="times-scroll" :class="{ 'times-scroll--overflowing': timesOverflow }">
            <div v-for="group in groupedSlots" :key="group.label" class="time-group">
              <div class="time-group-label">{{ group.label }}</div>
              <div class="slot-list">
                <button
                  v-for="slot in group.slots"
                  :key="slot.id"
                  type="button"
                  class="chip"
                  :class="{ selected: selectedSlotId === slot.id, 'chip--full': isFull(slot) }"
                  :title="isFull(slot) ? __('Full — join the waitlist', 'bookyzo') : undefined"
                  @click="selectedSlotId = slot.id"
                >
                  {{ formatTime(slot.start_datetime) }}<span v-if="isFull(slot)" class="chip-full-tag"> {{ __('· Full', 'bookyzo') }}</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      </template>
    </template>

    <!-- A full time was picked: the waitlist replaces Continue. -->
    <WaitlistForm v-if="selectedSlot && isFull(selectedSlot)" :slot="selectedSlot" :item="item" :party-size="partySize" />

    <div class="actions">
      <button type="button" class="btn btn-outline" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</button>
      <button type="button" class="btn btn-primary" :disabled="!selectedSlot || isFull(selectedSlot)" @click="confirm">{{ __('Continue', 'bookyzo') }}</button>
    </div>
  </div>
</template>

<script setup>
import { i18n, locale } from '@/lib/i18n';
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { getSlots, getSlotsForService } from '../api';
import { copyFor } from '../copy';
import WidgetLoading from '../WidgetLoading.vue';
import WaitlistForm from './WaitlistForm.vue';

const props = defineProps({
  item: { type: Object, required: true },
});
const emit = defineEmits(['selected', 'back']);

const allSlots = ref([]);
const loading = ref(true);
const error = ref('');
const partySize = ref(1);

// Everything downstream (calendar availability, time groups, the event
// list) reads this rather than the raw response, so choosing a party of
// six removes the tables that can't seat six instead of letting the
// visitor pick a time that will fail on submit.
// Full slots only arrive when the site keeps a waitlist (QA report
// §6.2.6); they stay listed, marked, so the visitor can join it. A full
// table only if the table could seat the party at all.
const waitlistOn = computed(() => Boolean(window.byzoWidget && window.byzoWidget.waitlistEnabled) && !props.item.service_id);

function isFull(slot) {
  return !!slot && slot.status === 'full';
}

const slots = computed(() =>
  allSlots.value.filter((s) => {
    if (isFull(s)) {
      return waitlistOn.value && (!asksPartySizeFirst.value || (props.item.capacity || 1) >= partySize.value);
    }
    return asksPartySizeFirst.value ? (s.capacity_remaining ?? 1) >= partySize.value : true;
  })
);
const selectedDateKey = ref('');
// Picking a time no longer advances the step on its own — the visitor
// confirms with Continue, matching every other step in the flow and
// leaving room to change their mind before committing.
const selectedSlotId = ref(null);
const monthCursor = ref(null);

const dayNames = [
  i18n._x('Su', 'Sunday, two letters', 'bookyzo'),
  i18n._x('Mo', 'Monday, two letters', 'bookyzo'),
  i18n._x('Tu', 'Tuesday, two letters', 'bookyzo'),
  i18n._x('We', 'Wednesday, two letters', 'bookyzo'),
  i18n._x('Th', 'Thursday, two letters', 'bookyzo'),
  i18n._x('Fr', 'Friday, two letters', 'bookyzo'),
  i18n._x('Sa', 'Saturday, two letters', 'bookyzo'),
];

function toDate(iso) {
  return new Date(iso.replace(' ', 'T'));
}

function dateKey(iso) {
  return iso.slice(0, 10);
}

function monthKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function formatTime(iso) {
  return toDate(iso).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
}

const copy = computed(() => copyFor(props.item.type));
const isEvent = computed(() => props.item.type === 'event');

// Table only — see the template comment. Capacity 1 means there is
// nothing to choose, so the control is omitted rather than shown with a
// single option.
const asksPartySizeFirst = computed(() => props.item.type === 'table' && (props.item.capacity || 1) > 1);

const partySizeOptions = computed(() => {
  const max = Math.max(1, props.item.capacity || 1);
  return Array.from({ length: max }, (_, i) => i + 1);
});

const emptyMessage = computed(() => {
  if (isEvent.value) {
    return i18n.__('No upcoming dates for this event right now.', 'bookyzo');
  }
  return asksPartySizeFirst.value && partySize.value > 1
    ? i18n.sprintf(i18n.__('No times available for %s in the next few weeks.', 'bookyzo'), partySize.value)
    : i18n.__('No available times in the next few weeks.', 'bookyzo');
});

function formatOccurrenceDate(iso) {
  return toDate(iso).toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric' });
}

// Only meaningful for a multi-capacity event; a sold-out occurrence still
// lists (so the date isn't silently missing) but says so.
function isSoldOut(slot) {
  const left = slot.capacity_remaining;
  return left !== null && left !== undefined && left <= 0;
}

function seatsLabel(slot) {
  const left = slot.capacity_remaining;
  if (left === null || left === undefined) return '';
  return isSoldOut(slot) ? copy.value.soldOut : copy.value.remaining(left);
}

// Every date that has at least one slot, as key → Date.
const availableDates = computed(() => {
  const seen = new Map();
  slots.value.forEach((slot) => {
    const key = dateKey(slot.start_datetime);
    if (!seen.has(key)) {
      seen.set(key, toDate(slot.start_datetime));
    }
  });
  return seen;
});

// Days with at least one bookable (not full) time.
const openDateKeys = computed(() => new Set(slots.value.filter((s) => !isFull(s)).map((s) => dateKey(s.start_datetime))));

const dates = computed(() =>
  Array.from(availableDates.value.keys()).sort((a, b) => a.localeCompare(b))
);

// Only months that actually contain availability are reachable, so the
// arrows can never walk into an empty stretch of calendar.
const availableMonths = computed(() => {
  const months = new Set();
  availableDates.value.forEach((date) => months.add(monthKey(date)));
  return Array.from(months).sort();
});

const currentMonthIndex = computed(() => availableMonths.value.indexOf(monthCursor.value));
const canGoPrev = computed(() => currentMonthIndex.value > 0);
const canGoNext = computed(() => currentMonthIndex.value > -1 && currentMonthIndex.value < availableMonths.value.length - 1);

function shiftMonth(delta) {
  const next = availableMonths.value[currentMonthIndex.value + delta];
  if (next) {
    monthCursor.value = next;
  }
}

const monthLabel = computed(() => {
  if (!monthCursor.value) return '';
  const [year, month] = monthCursor.value.split('-').map(Number);
  return new Date(year, month - 1, 1).toLocaleDateString(locale, { month: 'long', year: 'numeric' });
});

// Leading blanks so the 1st lands under its real weekday, then one cell
// per day of the month.
const monthCells = computed(() => {
  if (!monthCursor.value) return [];
  const [year, month] = monthCursor.value.split('-').map(Number);
  const first = new Date(year, month - 1, 1);
  const daysInMonth = new Date(year, month, 0).getDate();

  const cells = new Array(first.getDay()).fill(null);
  for (let day = 1; day <= daysInMonth; day += 1) {
    const key = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    cells.push({ key, day, available: availableDates.value.has(key), fullOnly: !openDateKeys.value.has(key) });
  }
  return cells;
});

const selectedDateLabel = computed(() => {
  const date = availableDates.value.get(selectedDateKey.value);
  return date ? date.toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric' }) : '';
});

const slotsForSelectedDate = computed(() =>
  slots.value.filter((s) => dateKey(s.start_datetime) === selectedDateKey.value)
);

const GROUPS = [
  { label: i18n.__('Morning', 'bookyzo'), test: (h) => h < 12 },
  { label: i18n.__('Afternoon', 'bookyzo'), test: (h) => h >= 12 && h < 17 },
  { label: i18n.__('Evening', 'bookyzo'), test: (h) => h >= 17 },
];

const groupedSlots = computed(() =>
  GROUPS.map((group) => ({
    label: group.label,
    slots: slotsForSelectedDate.value.filter((s) => group.test(toDate(s.start_datetime).getHours())),
  })).filter((group) => group.slots.length > 0)
);

const selectedSlot = computed(() => slots.value.find((s) => s.id === selectedSlotId.value) || null);

// Drives the bottom fade, which should only appear when times are
// actually cut off. CSS can't test for overflow, so it's measured here
// and re-measured whenever the layout could have changed (a different
// day's times, or the column wrapping at a new width).
const scrollEl = ref(null);
const timesOverflow = ref(false);

function measureOverflow() {
  const el = scrollEl.value;
  timesOverflow.value = !!el && el.scrollHeight > el.clientHeight + 1;
}

const resizeObserver =
  typeof ResizeObserver !== 'undefined' ? new ResizeObserver(measureOverflow) : null;

onBeforeUnmount(() => {
  resizeObserver?.disconnect();
});

// Re-measure after the new day's chips have rendered, and keep observing
// the element itself (it is recreated when the times block re-renders).
watch(
  [groupedSlots, scrollEl],
  async () => {
    await nextTick();
    resizeObserver?.disconnect();
    if (scrollEl.value) {
      resizeObserver?.observe(scrollEl.value);
    }
    measureOverflow();
  },
  { immediate: true }
);

function confirm() {
  if (selectedSlot.value) {
    // Second argument is the party size when this step collected it, so
    // StepDetails knows not to ask again. Undefined for every other type.
    emit('selected', selectedSlot.value, asksPartySizeFirst.value ? partySize.value : undefined);
  }
}

// Changing the day clears a time chosen on the previous one, so Continue
// can never submit a slot the visitor can no longer see.
watch(selectedDateKey, () => {
  selectedSlotId.value = null;
});

// Raising the party size can filter out the slot already chosen; drop it
// rather than letting Continue submit a table that no longer fits.
watch(partySize, () => {
  if (selectedSlotId.value && !slots.value.some((s) => s.id === selectedSlotId.value)) {
    selectedSlotId.value = null;
  }
});

// Calendar-only: events have no day-then-time drilldown, and defaulting a
// day there would clear the occurrence the visitor just picked (via the
// selectedDateKey watcher above).
watch(dates, (list) => {
  if (isEvent.value) {
    return;
  }
  if (list.length > 0 && !list.includes(selectedDateKey.value)) {
    // The first day something can actually be booked, not a full one.
    const first = list.find((key) => openDateKeys.value.has(key)) || list[0];
    selectedDateKey.value = first;
    monthCursor.value = monthKey(availableDates.value.get(first));
  }
});

// "Any available staff": /slots?service_id= returns every staff member's
// slot, so two free people at 10:00 would show 10:00 twice. One chip per
// start time — the server picks who at booking (BYZO_Booking::create_for_service()).
function onePerStartTime(list) {
  const seen = new Set();
  return list.filter((slot) => (seen.has(slot.start_datetime) ? false : seen.add(slot.start_datetime)));
}

// Times are shown on the item's own clock — its location's timezone (QA
// report §6.1 A1) — so start_datetime becomes the local time here, and the
// stored site time rides along as site_start_datetime for anything sent
// back to the server by time rather than by slot id.
function onLocalClock(list) {
  return list.map((slot) => ({
    ...slot,
    site_start_datetime: slot.start_datetime,
    start_datetime: slot.local_start_datetime || slot.start_datetime,
    end_datetime: slot.local_end_datetime || slot.end_datetime,
  }));
}

onMounted(async () => {
  try {
    allSlots.value = onLocalClock(
      props.item.service_id && !props.item.staff_id
        ? onePerStartTime(await getSlotsForService(props.item.service_id))
        : await getSlots(props.item.id, waitlistOn.value)
    );
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
});
</script>
