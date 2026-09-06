<template>
  <h1>Welcome back<template v-if="displayName">, {{ displayName }}</template></h1>

  <p v-if="loading" class="bookyzo-account__empty">Loading your bookings…</p>
  <p v-else-if="loadError" class="bookyzo-account__empty">Couldn't load your bookings. Please refresh the page.</p>
  <p v-else-if="bookings.length === 0" class="bookyzo-account__empty">You don't have any bookings yet.</p>
  <ul v-else class="bookyzo-account__booking-list">
    <li v-for="booking in bookings" :key="booking.id" class="bookyzo-account__booking">
      <div class="bookyzo-account__booking-title">{{ booking.customer_name }}</div>
      <div class="bookyzo-account__booking-meta">
        <template v-if="booking.slot_start">{{ formatDate(booking.slot_start) }} · </template>{{ booking.status }}
      </div>
      <button
        v-if="canCancel(booking)"
        type="button"
        class="bookyzo-account__btn"
        :disabled="cancellingId === booking.id"
        @click="cancel(booking)"
      >
        {{ cancellingId === booking.id ? 'Cancelling…' : 'Cancel booking' }}
      </button>
    </li>
  </ul>

  <form method="post" :action="logoutUrl">
    <button type="submit" class="bookyzo-account__btn">Log out</button>
  </form>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { getMyBookings, cancelMyBooking } from '../api';

// Reschedule is deliberately not wired up here yet — it needs a slot
// picker (which type, which date, which time), a real UI surface of its
// own rather than a one-click action like cancel. The backend route
// (PUT /bookings/{id}/reschedule, ownership-checked the same way cancel
// is) is already widened per DETAILED-SPEC.md §A11, ready for whichever
// future step builds that picker.
defineProps({
  logoutUrl: { type: String, required: true },
  displayName: { type: String, default: '' },
});

const bookings     = ref([]);
const loading      = ref(true);
const loadError    = ref(false);
const cancellingId = ref(null);

onMounted(async () => {
  try {
    bookings.value = await getMyBookings();
  } catch (e) {
    loadError.value = true;
  } finally {
    loading.value = false;
  }
});

function canCancel(booking) {
  return ['pending', 'confirmed'].includes(booking.status);
}

function formatDate(mysqlDateTime) {
  return new Date(mysqlDateTime.replace(' ', 'T')).toLocaleString();
}

async function cancel(booking) {
  cancellingId.value = booking.id;
  try {
    const updated = await cancelMyBooking(booking.id);
    const idx = bookings.value.findIndex((b) => b.id === booking.id);
    if (idx !== -1) bookings.value[idx] = updated;
  } catch (e) {
    // Server-side error (e.g. outside the cancellation window) — the
    // booking's own status is left as-is; a page refresh (or the next
    // dashboard visit) always re-derives the real current state from the
    // server, same "server is the source of truth" rule the old
    // manage-booking.php page followed.
    window.alert(e.message || 'Could not cancel this booking.');
  } finally {
    cancellingId.value = null;
  }
}
</script>
