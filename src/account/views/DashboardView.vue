<template>
  <h1>{{ justRegistered ? __('Welcome', 'bookyzo') : __('Welcome back', 'bookyzo') }}<template v-if="displayName">, {{ displayName }}</template></h1>

  <p v-if="loading" class="bookyzo-account__empty">{{ __('Loading your bookings…', 'bookyzo') }}</p>
  <p v-else-if="loadError" class="bookyzo-account__empty">{{ __('Couldn\'t load your bookings. Please refresh the page.', 'bookyzo') }}</p>
  <p v-else-if="bookings.length === 0" class="bookyzo-account__empty">{{ __('You don\'t have any bookings yet.', 'bookyzo') }}</p>
  <ul v-else class="bookyzo-account__booking-list">
    <li v-for="booking in bookings" :key="booking.id" class="bookyzo-account__booking">
      <div class="bookyzo-account__booking-title">{{ booking.customer_name }}</div>
      <div class="bookyzo-account__booking-meta">
        <template v-if="booking.slot_start">{{ formatDate(booking.local_slot_start || booking.slot_start) }} · </template>{{ statusLabel(booking.status) }}
      </div>
      <button
        v-if="canCancel(booking)"
        type="button"
        class="bookyzo-account__btn"
        :disabled="cancellingId === booking.id"
        @click="cancel(booking)"
      >
        {{ cancellingId === booking.id ? __('Cancelling…', 'bookyzo') : __('Cancel booking', 'bookyzo') }}
      </button>
      <!-- Plain browser-navigable link, not a fetch() call — see
           api.js's getInvoiceUrl() docblock for why. Only ever rendered
           when Pro's PDF renderer is actually active (never a dead link,
           per this project's standing Guideline-5 rule). -->
      <a v-if="pdfInvoiceEnabled" :href="getInvoiceUrl(booking.id)" class="bookyzo-account__btn bookyzo-account__btn--link">
        {{ __('Download invoice', 'bookyzo') }}
      </a>
      <!-- Deliberately not restricted to Event-type bookings — same
           no-real-backend-reason-to-restrict call already made for
           commission tracking (DETAILED-SPEC.md §3 B7 Appointment,
           step 12): a QR check-in ticket is equally meaningful for a
           table or appointment arrival, not just an event ticket. -->
      <a v-if="qrTicketEnabled" :href="getTicketQrUrl(booking.id)" class="bookyzo-account__btn bookyzo-account__btn--link">
        {{ __('View ticket', 'bookyzo') }}
      </a>
    </li>
  </ul>

  <!-- An add-on's sections (e.g. the customer's packages), plain text only. -->
  <section v-for="(section, index) in sections" :key="index" class="bookyzo-account__section">
    <h2 class="bookyzo-account__section-title">{{ section.title }}</h2>
    <p v-if="!section.items.length" class="bookyzo-account__empty">{{ section.empty }}</p>
    <ul v-else class="bookyzo-account__booking-list">
      <li v-for="(item, i) in section.items" :key="i" class="bookyzo-account__booking">
        <div class="bookyzo-account__booking-title">{{ item.title }}</div>
        <div v-for="(line, j) in item.lines" :key="j" class="bookyzo-account__booking-meta">{{ line }}</div>
      </li>
    </ul>
  </section>

  <form method="post" :action="logoutUrl">
    <button type="submit" class="bookyzo-account__btn">{{ __('Log out', 'bookyzo') }}</button>
  </form>
</template>

<script setup>
import { statusLabel } from '@/lib/status';
import { i18n, locale } from '@/lib/i18n';
import { ref, onMounted } from 'vue';
import { getMyBookings, cancelMyBooking, getInvoiceUrl, getTicketQrUrl } from '../api';

// Reschedule is deliberately not wired up here yet — it needs a slot
// picker (which type, which date, which time), a real UI surface of its
// own rather than a one-click action like cancel. The backend route
// (PUT /bookings/{id}/reschedule, ownership-checked the same way cancel
// is) is already widened per DETAILED-SPEC.md §A11, ready for whichever
// future step builds that picker.
defineProps({
  logoutUrl: { type: String, required: true },
  displayName: { type: String, default: '' },
  // Straight after creating the account — "Welcome back" read oddly there.
  justRegistered: { type: Boolean, default: false },
  pdfInvoiceEnabled: { type: Boolean, default: false },
  qrTicketEnabled: { type: Boolean, default: false },
  sections: { type: Array, default: () => [] },
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
  return new Date(mysqlDateTime.replace(' ', 'T')).toLocaleString(locale);
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
    window.alert(e.message || i18n.__('Could not cancel this booking.', 'bookyzo'));
  } finally {
    cancellingId.value = null;
  }
}
</script>
