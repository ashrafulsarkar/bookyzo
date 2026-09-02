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
      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <div v-else class="flex flex-col gap-4">
        <Card v-for="event in events" :key="event.id" class="max-w-2xl">
          <CardHeader>
            <CardTitle>{{ event.label }}</CardTitle>
            <CardDescription>{{ event.description }}</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-medium" :for="`subject-${event.id}`">Subject</label>
              <input
                :id="`subject-${event.id}`"
                v-model="templates[event.id].subject"
                type="text"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
            </div>
            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-medium" :for="`body-${event.id}`">Body</label>
              <textarea
                :id="`body-${event.id}`"
                v-model="templates[event.id].body"
                rows="6"
                class="rounded-md border border-input bg-background px-3 py-2 font-mono text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              <p class="text-xs text-muted-foreground">
                Placeholders: <code>{customer_name}</code> <code>{resource_name}</code> <code>{start_time}</code>
                <code>{business_name}</code>
              </p>
            </div>
          </CardContent>
        </Card>
      </div>

      <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
      <p v-if="saved" class="mt-4 text-sm" style="color: var(--byzo-success)">Saved.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import { api } from '@/api/client';

// Free = plain textarea per event, no visual/HTML builder
// (DETAILED-SPEC.md §A6 — that's a Pro feature layered on the same
// stored subject/body values later, not a different data shape now).
const events = [
  { id: 'booking_created', label: 'Booking received', description: 'Sent immediately when a customer books.' },
  { id: 'booking_confirmed', label: 'Booking confirmed', description: 'Sent when you confirm a pending booking.' },
  { id: 'booking_cancelled', label: 'Booking cancelled', description: 'Sent when a booking is cancelled.' },
  { id: 'booking_reminder', label: 'Reminder', description: 'Sent automatically ~24 hours before the appointment.' },
];

const templates = ref({});
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const saved = ref(false);

async function load() {
  loading.value = true;
  try {
    templates.value = await api.get('/email-templates');
  } catch (e) {
    error.value = e.message || 'Could not load email templates.';
  } finally {
    loading.value = false;
  }
}

async function save() {
  error.value = '';
  saved.value = false;
  saving.value = true;
  try {
    templates.value = await api.put('/email-templates', templates.value);
    saved.value = true;
  } catch (e) {
    error.value = e.message || 'Could not save email templates.';
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
