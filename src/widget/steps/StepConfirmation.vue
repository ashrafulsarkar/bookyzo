<template>
  <div class="confirmation">
    <div class="check">✓</div>
    <h2>{{ copy.confirmHeading }}</h2>
    <p v-if="booking?.status === 'confirmed'">{{ sprintf(__('A confirmation has been sent to %s.', 'bookyzo'), booking?.customer_email) }}</p>
    <p v-else>{{ sprintf(__('A confirmation will be sent to %s once it\'s approved.', 'bookyzo'), booking?.customer_email) }}</p>

    <!-- The booking restated as a scannable card rather than buried in a
         sentence — this is the screen people screenshot or come back to. -->
    <div class="summary summary--confirm">
      <div class="summary-row">
        <span class="summary-label">{{ copy.subjectLabel }}</span>
        <span class="summary-value">{{ item?.name }}</span>
      </div>
      <div v-if="booking?.slot_start" class="summary-row">
        <!-- On the booked item's own clock (QA report §6.1 A1). -->
        <span class="summary-label">{{ __('When', 'bookyzo') }}</span>
        <span class="summary-value">{{ sprintf(__('%1$s at %2$s', 'bookyzo'), formatDate(booking.local_slot_start || booking.slot_start), formatTime(booking.local_slot_start || booking.slot_start)) }}</span>
      </div>
      <div v-if="item?.location" class="summary-row">
        <span class="summary-label">{{ __('Where', 'bookyzo') }}</span>
        <span class="summary-value">{{ item.location }}</span>
      </div>
    </div>

    <p v-if="item?.meeting_link" class="location">
      <a :href="item.meeting_link" target="_blank" rel="noopener noreferrer">{{ __('Join meeting link', 'bookyzo') }}</a>
    </p>
    <!-- QR e-ticket and invoice (QA report 2026-09-22 B10) — a guest has no
         customer account to fetch them from, so they're offered here, via
         the same signed token as the "manage booking" link. -->
    <div v-if="ticketUrl" class="ticket">
      <img :src="ticketUrl" :alt="__('Your e-ticket QR code', 'bookyzo')" width="180" height="180" />
      <p class="ticket-note">{{ __('Show this code at the door. It\'s in your confirmation email too.', 'bookyzo') }}</p>
    </div>
    <p v-if="invoiceUrl" class="location">
      <a :href="invoiceUrl" target="_blank" rel="noopener noreferrer">{{ __('Download invoice (PDF)', 'bookyzo') }}</a>
    </p>
    <div v-if="booking?.slot_start_gmt" class="add-to-calendar">
      <a :href="googleCalendarUrl" target="_blank" rel="noopener noreferrer">{{ __('Add to Google Calendar', 'bookyzo') }}</a>
      <span class="sep">·</span>
      <a href="#" @click.prevent="downloadIcs">{{ __('Download .ics', 'bookyzo') }}</a>
    </div>
    <!-- Booking-time account opt-in result (Phase 1.7 step 6) — only
         present at all when the widget actually sent create_account:true;
         a booking that succeeded is never blocked or hidden behind this,
         it's purely an acknowledgment of the secondary action. -->
    <p v-if="booking?.account_created" class="account-note">{{ __('Your account has been created — you\'re now logged in.', 'bookyzo') }}</p>
    <p v-else-if="booking?.account_error" class="account-note account-note--error">{{ sprintf(__('Your booking was made, but the account couldn\'t be created: %s', 'bookyzo'), booking.account_error) }}</p>
  </div>
</template>

<script setup>
import { i18n, locale } from '@/lib/i18n';
import { computed } from 'vue';
import { copyFor } from '../copy';

const props = defineProps({
  booking: { type: Object, default: null },
  item: { type: Object, default: null },
});

const copy = computed(() => copyFor(props.item?.type));

const documents = (window.byzoWidget && window.byzoWidget.documents) || {};
const restBase = (window.byzoWidget && window.byzoWidget.restUrl) || '/wp-json/bookyzo/v1';
function documentUrl(document) {
  const { id, manage_token: token } = props.booking || {};
  return id && token ? `${restBase}/bookings/${id}/${document}?token=${encodeURIComponent(token)}` : '';
}
// Same rules the confirmation email uses (BYZO_Notifications::document_link()).
const ticketUrl = computed(() =>
  documents.ticket && props.item?.type === 'event' && props.booking?.status === 'confirmed' ? documentUrl('ticket-qr') : ''
);
const invoiceUrl = computed(() =>
  documents.invoice && props.booking?.payment_amount !== null && props.booking?.payment_amount !== undefined ? documentUrl('invoice') : ''
);

function toLocalDate(iso) {
  return new Date(iso.replace(' ', 'T'));
}
function formatDate(iso) {
  return toLocalDate(iso).toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric' });
}
function formatTime(iso) {
  return toLocalDate(iso).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
}

// "Add to calendar" (DETAILED-SPEC.md §A6, Phase 1.5 step 9) — computed
// on the fly client-side, not stored server-side: everything it needs
// (item name, slot_start/slot_end) is already in these two props from
// the booking-creation response. Google Calendar's own render?action=TEMPLATE
// deep link needs no API call, just a query-string-encoded URL.
//
// slot_start/slot_end are the SITE's wall-clock time with no zone attached,
// so converting them here would read them in the VISITOR's timezone — a
// Dhaka visitor booking 09:00 on a UTC site got a 03:00Z event (QA report
// 2026-09-22 B7). The server sends the real instants as slot_start_gmt/
// slot_end_gmt (ISO 8601, UTC) for exactly this.
function toUtcStamp(isoUtc) {
  return new Date(isoUtc).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
}

const startGmt = computed(() => props.booking?.slot_start_gmt);
const endGmt = computed(() => props.booking?.slot_end_gmt || props.booking?.slot_start_gmt);

const googleCalendarUrl = computed(() => {
  if (!startGmt.value) return '#';
  const start = toUtcStamp(startGmt.value);
  const end = toUtcStamp(endGmt.value);
  const params = new URLSearchParams({
    action: 'TEMPLATE',
    text: props.item?.name || i18n.__('Booking', 'bookyzo'),
    dates: `${start}/${end}`,
  });
  return `https://calendar.google.com/calendar/render?${params.toString()}`;
});

function icsContent() {
  const start = toUtcStamp(startGmt.value);
  const end = toUtcStamp(endGmt.value);
  const title = (props.item?.name || i18n.__('Booking', 'bookyzo')).replace(/[\r\n]/g, ' ');
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
