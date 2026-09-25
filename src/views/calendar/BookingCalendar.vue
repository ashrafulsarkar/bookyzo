<template>
  <div>
    <ByzoPageHeader :title="__('Calendar', 'bookyzo')" :subtitle="__('Every booking, by day, week, or month.', 'bookyzo')">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <path d="M16 2v4M8 2v4M3 10h18" />
        </svg>
      </template>
      <template #actions>
        <Select v-model="itemFilter">
          <SelectTrigger class="h-9 w-44"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">{{ __('All bookable items', 'bookyzo') }}</SelectItem>
            <SelectItem v-for="item in items" :key="item.id" :value="String(item.id)">{{ item.name }}</SelectItem>
          </SelectContent>
        </Select>
        <Select v-if="locations.length" v-model="locationFilter">
          <SelectTrigger class="h-9 w-44"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">{{ __('All locations', 'bookyzo') }}</SelectItem>
            <SelectItem v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</SelectItem>
          </SelectContent>
        </Select>
        <Button @click="showManualBooking = true">{{ __('+ New booking', 'bookyzo') }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <Card class="overflow-hidden">
        <ByzoCalendar
          :bookings="bookings"
          :items="items"
          @range-change="onRangeChange"
          @select-booking="openDetail"
          @select-slot="onSelectSlot"
          @reschedule-booking="onRescheduleBooking"
        />
      </Card>
      <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
    </div>

    <BookingDetailModal
      v-if="activeBooking"
      :booking="activeBooking"
      @close="activeBooking = null"
      @updated="onBookingUpdated"
      @changed="loadBookings"
    />
    <ManualBookingModal
      v-if="showManualBooking"
      :initial-bookable-item-id="itemFilter !== '__all__' ? itemFilter : null"
      @close="showManualBooking = false"
      @created="onBookingCreated"
    />
  </div>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
import { ref, watch, onMounted } from 'vue';
import { extraLocations as locations } from '@/lib/admin-extensions';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoCalendar from '@/components/base/ByzoCalendar.vue';
import BookingDetailModal from './BookingDetailModal.vue';
import ManualBookingModal from './ManualBookingModal.vue';
import { api } from '@/api/client';
import { toDateKey } from '@/lib/date';
import { toast } from 'vue-sonner';

const items = ref([]);
const itemFilter = ref('__all__');
// Location filter (QA report §6.1 A1) — only when an add-on has locations.
const locationFilter = ref('__all__');
watch([itemFilter, locationFilter], () => loadBookings());
const bookings = ref([]);
const error = ref('');
const activeBooking = ref(null);
const showManualBooking = ref(false);
let currentRange = null;

async function loadItems() {
  try {
    items.value = await api.get('/bookable-items?per_page=100');
  } catch (e) {
    // Non-fatal — the filter dropdown just stays empty; the calendar
    // itself still works unfiltered.
  }
}

async function loadBookings() {
  if (!currentRange) {
    return;
  }
  error.value = '';
  try {
    const params = new URLSearchParams({
      from: toDateKey(currentRange.from),
      to: toDateKey(currentRange.to),
      per_page: '100',
    });
    if (itemFilter.value !== '__all__') {
      params.set('bookable_item_id', itemFilter.value);
    }
    if (locationFilter.value !== '__all__') {
      params.set('location_id', locationFilter.value);
    }
    bookings.value = await api.get(`/bookings?${params.toString()}`);
  } catch (e) {
    error.value = e.message || i18n.__('Could not load bookings.', 'bookyzo');
  }
}

function onRangeChange(range) {
  currentRange = range;
  loadBookings();
}

function openDetail(booking) {
  activeBooking.value = booking;
}

function onBookingUpdated(updated) {
  bookings.value = bookings.value.map((b) => (b.id === updated.id ? updated : b));
  activeBooking.value = updated;
  if (['completed', 'cancelled', 'no_show', 'rejected'].includes(updated.status)) {
    activeBooking.value = null;
  }
}

function onBookingCreated() {
  showManualBooking.value = false;
  loadBookings();
}

/**
 * Drag-and-drop reschedule (DETAILED-SPEC.md §3 B3, Phase 2 step 11) —
 * resolves the calendar's rough drop target against a REAL generated
 * open slot for this bookable item before calling the same unrestricted
 * PUT /bookings/{id}/reschedule the detail modal's own dropdown already
 * uses. Dropping on a time with no matching real slot fails honestly
 * (a toast) rather than silently snapping to the nearest anything.
 */
async function onRescheduleBooking({ booking, date, minutes }) {
  const dateKey = toDateKey(date);

  let slots;
  try {
    const params = new URLSearchParams({
      bookable_item_id: String(booking.bookable_item_id),
      from: dateKey,
      to: dateKey,
    });
    slots = await api.get(`/slots?${params.toString()}`);
  } catch (e) {
    toast.error(e.message || i18n.__('Could not check availability for that time.', 'bookyzo'));
    return;
  }

  const targetMatch = slots.find((s) => {
    const start = new Date(s.start_datetime);
    return start.getHours() * 60 + start.getMinutes() === minutes;
  });

  if (!targetMatch) {
    toast.error(i18n.__('No available time there — drop it on an open slot.', 'bookyzo'));
    return;
  }

  try {
    await api.put(`/bookings/${booking.id}/reschedule`, { slot_id: targetMatch.id });
    toast.success(i18n.__('Booking rescheduled.', 'bookyzo'));
    loadBookings();
  } catch (e) {
    toast.error(e.message || i18n.__('Could not reschedule this booking.', 'bookyzo'));
  }
}

function onSelectSlot() {
  // Clicking an empty calendar cell opens the same manual-booking flow —
  // the bookable item/time are picked inside the modal rather than trying
  // to infer an exact slot from a click position (buffer time and shift
  // boundaries make that error-prone; the modal's own slot dropdown is
  // the source of truth for what's actually bookable).
  showManualBooking.value = true;
}

onMounted(async () => {
  await loadItems();
});
</script>
