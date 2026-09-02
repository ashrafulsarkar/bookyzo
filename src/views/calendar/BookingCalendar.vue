<template>
  <div>
    <ByzoPageHeader title="Calendar" subtitle="Every booking, by day, week, or month.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <path d="M16 2v4M8 2v4M3 10h18" />
        </svg>
      </template>
      <template #actions>
        <select
          v-model="resourceFilter"
          class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <option value="">All resources</option>
          <option v-for="resource in resources" :key="resource.id" :value="resource.id">{{ resource.name }}</option>
        </select>
        <Button @click="showManualBooking = true">+ New booking</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <Card class="overflow-hidden">
        <ByzoCalendar :bookings="bookings" @range-change="onRangeChange" @select-booking="openDetail" @select-slot="onSelectSlot" />
      </Card>
      <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
    </div>

    <BookingDetailModal v-if="activeBooking" :booking="activeBooking" @close="activeBooking = null" @updated="onBookingUpdated" />
    <ManualBookingModal
      v-if="showManualBooking"
      :initial-resource-id="resourceFilter || null"
      @close="showManualBooking = false"
      @created="onBookingCreated"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoCalendar from '@/components/base/ByzoCalendar.vue';
import BookingDetailModal from './BookingDetailModal.vue';
import ManualBookingModal from './ManualBookingModal.vue';
import { api } from '@/api/client';
import { toDateKey } from '@/lib/date';

const resources = ref([]);
const resourceFilter = ref('');
const bookings = ref([]);
const error = ref('');
const activeBooking = ref(null);
const showManualBooking = ref(false);
let currentRange = null;

async function loadResources() {
  try {
    resources.value = await api.get('/resources?per_page=100');
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
    if (resourceFilter.value) {
      params.set('resource_id', resourceFilter.value);
    }
    bookings.value = await api.get(`/bookings?${params.toString()}`);
  } catch (e) {
    error.value = e.message || 'Could not load bookings.';
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
  if (['completed', 'cancelled', 'no_show'].includes(updated.status)) {
    activeBooking.value = null;
  }
}

function onBookingCreated() {
  showManualBooking.value = false;
  loadBookings();
}

function onSelectSlot() {
  // Clicking an empty calendar cell opens the same manual-booking flow —
  // resource/time are picked inside the modal rather than trying to
  // infer an exact slot from a click position (buffer time and shift
  // boundaries make that error-prone; the modal's own slot dropdown is
  // the source of truth for what's actually bookable).
  showManualBooking.value = true;
}

onMounted(async () => {
  await loadResources();
});
</script>
