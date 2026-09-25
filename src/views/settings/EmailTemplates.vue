<template>
  <div>
    <ByzoPageHeader :title="__('Email Templates', 'bookyzo')" :subtitle="__('Plain-text notifications sent to customers.', 'bookyzo')">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="2" y="4" width="20" height="16" rx="2" />
          <path d="m22 7-10 6L2 7" />
        </svg>
      </template>
      <template #actions>
        <Button :disabled="loading || saving" @click="save">{{ saving ? __('Saving…', 'bookyzo') : __('Save all', 'bookyzo') }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <ByzoLoading v-if="loading" />

      <div v-else class="flex flex-col gap-4">
        <Card v-for="event in events" :key="event.id">
          <CardHeader>
            <CardTitle>{{ event.label }}</CardTitle>
            <CardDescription>{{ event.description }}</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <div class="flex flex-col gap-1.5">
              <Label :for="`subject-${event.id}`">{{ __('Subject', 'bookyzo') }}</Label>
              <Input :id="`subject-${event.id}`" v-model="templates[event.id].subject" type="text" />
            </div>
            <div class="flex flex-col gap-1.5">
              <Label :for="`body-${event.id}`">{{ __('Body', 'bookyzo') }}</Label>
              <!-- rows=6 cut every shipped default off mid-sentence — the
                   longest ("Booking received") runs 12 lines before wrapping,
                   so the box opened already hiding its own content and made
                   the templates look truncated rather than editable. -->
              <Textarea :id="`body-${event.id}`" v-model="templates[event.id].body" rows="14" class="resize-y font-mono" />
              <p class="text-xs text-muted-foreground">
                {{ __('Placeholders:', 'bookyzo') }} <code>{customer_name}</code> <code>{bookable_item_name}</code> <code>{start_time}</code>
                <code>{business_name}</code> <code>{cancel_link}</code> <code>{add_to_calendar_link}</code>
                <code>{booking_id}</code> <code>{party_size}</code> <code>{total}</code>
                <code>{customer_email}</code> <code>{customer_phone}</code> <code>{admin_booking_link}</code>
                <code>{location}</code> <code>{meeting_link}</code>
                <template v-if="documents.ticket"> <code>{ticket_link}</code></template>
                <template v-if="documents.invoice"> <code>{invoice_link}</code></template>
              </p>
              <p v-if="event.id === 'booking_confirmed' && (documents.ticket || documents.invoice)" class="text-xs text-muted-foreground">
                {{ sprintf(__('If this email doesn\'t use %s, the link is added at the end automatically.', 'bookyzo'), documentPlaceholders) }}
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  </div>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
import { ref, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import { api } from '@/api/client';

// Booking documents an add-on provides (QA report 2026-09-22 B10) — their
// placeholders are only listed when they exist.
const documents = (window.byzoAdmin && window.byzoAdmin.documents) || {};
const documentPlaceholders = [documents.ticket && '{ticket_link}', documents.invoice && '{invoice_link}'].filter(Boolean).join(' / ');

// Free = plain textarea per event, no visual/HTML builder
// (DETAILED-SPEC.md §A6 — that's a Pro feature layered on the same
// stored subject/body values later, not a different data shape now).
const events = [
  { id: 'booking_created', label: i18n.__('Booking received', 'bookyzo'), description: i18n.__('Sent immediately when a customer books.', 'bookyzo') },
  { id: 'booking_confirmed', label: i18n.__('Booking confirmed', 'bookyzo'), description: i18n.__('Sent when you confirm a pending booking (or immediately, if auto-confirm is on).', 'bookyzo') },
  { id: 'booking_cancelled', label: i18n.__('Booking cancelled', 'bookyzo'), description: i18n.__('Sent when a booking is cancelled.', 'bookyzo') },
  { id: 'booking_reminder', label: i18n.__('Reminder', 'bookyzo'), description: i18n.__('Sent automatically ~24 hours before the booking.', 'bookyzo') },
  // Added Phase 1.5 step 6 — the only one of the 5 sent to you (the site
  // admin) rather than the customer, gated by its own "Email me when a
  // new booking comes in" toggle (Settings / the setup wizard), separate
  // from the 4 customer-facing ones above.
  { id: 'admin_new_booking', label: i18n.__('New booking alert (to you)', 'bookyzo'), description: i18n.__('Sent to your site admin email whenever a new booking comes in.', 'bookyzo') },
];

const templates = ref({});
const loading = ref(true);
const saving = ref(false);

async function load() {
  loading.value = true;
  try {
    templates.value = await api.get('/email-templates');
  } catch (e) {
    toast.error(e.message || i18n.__('Could not load email templates.', 'bookyzo'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    templates.value = await api.put('/email-templates', templates.value);
    toast.success(i18n.__('Email templates saved.', 'bookyzo'));
  } catch (e) {
    toast.error(e.message || i18n.__('Could not save email templates.', 'bookyzo'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
