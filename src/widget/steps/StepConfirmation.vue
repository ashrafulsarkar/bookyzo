<template>
  <div class="confirmation">
    <div class="check">✓</div>
    <h2>Booking received</h2>
    <p>
      We've received your booking for <strong>{{ item?.name }}</strong>
      <template v-if="booking?.slot_start">
        on {{ formatDate(booking.slot_start) }} at {{ formatTime(booking.slot_start) }}
      </template>.
      A confirmation will be sent to {{ booking?.customer_email }} once it's approved.
    </p>
    <p v-if="item?.location || item?.meeting_link" class="location">
      <template v-if="item?.location">{{ item.location }}</template>
      <template v-if="item?.location && item?.meeting_link"> · </template>
      <a v-if="item?.meeting_link" :href="item.meeting_link" target="_blank" rel="noopener noreferrer">Join meeting link</a>
    </p>
    <div v-if="booking?.slot_start" class="add-to-calendar">
      <a :href="googleCalendarUrl" target="_blank" rel="noopener noreferrer">Add to Google Calendar</a>
      <span class="sep">·</span>
      <a href="#" @click.prevent="downloadIcs">Download .ics</a>
    </div>
    <!-- Booking-time account opt-in result (Phase 1.7 step 6) — only
         present at all when the widget actually sent create_account:true;
         a booking that succeeded is never blocked or hidden behind this,
         it's purely an acknowledgment of the secondary action. -->
    <p v-if="booking?.account_created" class="account-note">Your account has been created — you're now logged in.</p>
    <p v-else-if="booking?.account_error" class="account-note account-note--error">Your booking was made, but the account couldn't be created: {{ booking.account_error }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  booking: { type: Object, default: null },
  item: { type: Object, default: null },
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

// "Add to calendar" (DETAILED-SPEC.md §A6, Phase 1.5 step 9) — computed
// on the fly client-side, not stored server-side: everything it needs
// (item name, slot_start/slot_end) is already in these two props from
// the booking-creation response. Google Calendar's own render?action=TEMPLATE
// deep link needs no API call, just a query-string-encoded URL.
function toUtcStamp(iso) {
  return toLocalDate(iso).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
}

const googleCalendarUrl = computed(() => {
  if (!props.booking?.slot_start) return '#';
  const start = toUtcStamp(props.booking.slot_start);
  const end = toUtcStamp(props.booking.slot_end || props.booking.slot_start);
  const params = new URLSearchParams({
    action: 'TEMPLATE',
    text: props.item?.name || 'Booking',
    dates: `${start}/${end}`,
  });
  return `https://calendar.google.com/calendar/render?${params.toString()}`;
});

function icsContent() {
  const start = toUtcStamp(props.booking.slot_start);
  const end = toUtcStamp(props.booking.slot_end || props.booking.slot_start);
  const title = (props.item?.name || 'Booking').replace(/[\r\n]/g, ' ');
  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Bookyzo//Booking//EN',
    'BEGIN:VEVENT',
    `UID:byzo-booking-${props.booking.id}@${window.location.hostname}`,
    `DTSTAMP:${toUtcStamp(new Date().toISOString())}`,
    `DTSTART:${start}`,
    `DTEND:${end}`,
    `SUMMARY:${title}`,
    'END:VEVENT',
    'END:VCALENDAR',
  ].join('\r\n');
}

function downloadIcs() {
  const blob = new Blob([icsContent()], { type: 'text/calendar' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'booking.ics';
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
</script>
