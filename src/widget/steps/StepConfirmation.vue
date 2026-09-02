<template>
  <div class="confirmation">
    <div class="check">✓</div>
    <h2>Booking received</h2>
    <p>
      We've received your booking for <strong>{{ resource?.name }}</strong>
      <template v-if="booking?.slot_start">
        on {{ formatDate(booking.slot_start) }} at {{ formatTime(booking.slot_start) }}
      </template>.
      A confirmation will be sent to {{ booking?.customer_email }} once it's approved.
    </p>
  </div>
</template>

<script setup>
defineProps({
  booking: { type: Object, default: null },
  resource: { type: Object, default: null },
});

function toLocalDate(iso) {
  return new Date(iso.replace(' ', 'T'));
}
function formatDate(iso) {
  return toLocalDate(iso).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
}
function formatTime(iso) {
  return toLocalDate(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}
</script>
