<template>
  <div>
    <ByzoPageHeader title="Email Templates" subtitle="Plain-text notifications sent to customers.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="2" y="4" width="20" height="16" rx="2" />
          <path d="m22 7-10 6L2 7" />
        </svg>
      </template>
      <template #actions>
        <Button :disabled="loading || saving" @click="save">{{ saving ? 'Saving…' : 'Save all' }}</Button>
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
              <Label :for="`subject-${event.id}`">Subject</Label>
              <Input :id="`subject-${event.id}`" v-model="templates[event.id].subject" type="text" />
            </div>
            <div class="flex flex-col gap-1.5">
              <Label :for="`body-${event.id}`">Body</Label>
              <!-- rows=6 cut every shipped default off mid-sentence — the
                   longest ("Booking received") runs 12 lines before wrapping,
                   so the box opened already hiding its own content and made
                   the templates look truncated rather than editable. -->
              <Textarea :id="`body-${event.id}`" v-model="templates[event.id].body" rows="14" class="resize-y font-mono" />
              <p class="text-xs text-muted-foreground">
                Placeholders: <code>{customer_name}</code> <code>{bookable_item_name}</code> <code>{start_time}</code>
                <code>{business_name}</code> <code>{cancel_link}</code> <code>{add_to_calendar_link}</code>
                <code>{location}</code> <code>{meeting_link}</code>
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  </div>
</template>

<script setup>
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

// Free = plain textarea per event, no visual/HTML builder
// (DETAILED-SPEC.md §A6 — that's a Pro feature layered on the same
// stored subject/body values later, not a different data shape now).
const events = [
  { id: 'booking_created', label: 'Booking received', description: 'Sent immediately when a customer books.' },
  { id: 'booking_confirmed', label: 'Booking confirmed', description: 'Sent when you confirm a pending booking (or immediately, if auto-confirm is on).' },
  { id: 'booking_cancelled', label: 'Booking cancelled', description: 'Sent when a booking is cancelled.' },
  { id: 'booking_reminder', label: 'Reminder', description: 'Sent automatically ~24 hours before the booking.' },
  // Added Phase 1.5 step 6 — the only one of the 5 sent to you (the site
  // admin) rather than the customer, gated by its own "Email me when a
  // new booking comes in" toggle (Settings / the setup wizard), separate
  // from the 4 customer-facing ones above.
  { id: 'admin_new_booking', label: 'New booking alert (to you)', description: 'Sent to your site admin email whenever a new booking comes in.' },
];

const templates = ref({});
const loading = ref(true);
const saving = ref(false);

async function load() {
  loading.value = true;
  try {
    templates.value = await api.get('/email-templates');
  } catch (e) {
    toast.error(e.message || 'Could not load email templates.');
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    templates.value = await api.put('/email-templates', templates.value);
    toast.success('Email templates saved.');
  } catch (e) {
    toast.error(e.message || 'Could not save email templates.');
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
