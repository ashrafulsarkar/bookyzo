<template>
  <Card>
    <CardHeader>
      <CardTitle>Review</CardTitle>
      <CardDescription>{{ description }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-3 text-sm">
      <div
        v-for="row in rows"
        :key="row.label"
        class="flex justify-between gap-4 border-b border-border pb-2 last:border-0 last:pb-0"
      >
        <span class="shrink-0 text-muted-foreground">{{ row.label }}</span>
        <span class="break-words text-right font-medium">{{ row.value }}</span>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" :disabled="saving" @click="$emit('back')">Back</Button>
      <Button :disabled="saving" @click="$emit('create')">{{ saving ? savingLabel : submitLabel }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { computed } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';

// Shared by the Add wizard (BookableItemAddWizard.vue) and the Edit
// wizard (BookableItemEditDialog.vue) — the labels default to Add's, Edit
// passes its own. Same field matrix as the setup wizard's ReviewStep.vue,
// minus the phone/auto-confirm/notification rows neither popup collects.
const props = defineProps({
  data: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
  description: { type: String, default: 'Everything look right? Create this bookable item.' },
  submitLabel: { type: String, default: 'Create bookable item' },
  savingLabel: { type: String, default: 'Creating…' },
});
defineEmits(['create', 'back']);

const TYPE_LABELS = { appointment: 'Appointment', table: 'Table', event: 'Event', slot: 'Slot' };
const STATUS_LABELS = { active: 'Active', inactive: 'Inactive', archived: 'Archived' };
const WEEKDAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const availabilitySummary = computed(() => {
  if ('event' === props.data.type) {
    return props.data.occurrence?.date
      ? `${props.data.occurrence.date}, ${props.data.occurrence.start_time}–${props.data.occurrence.end_time}`
      : '—';
  }

  const open = (props.data.schedules || []).slice().sort((a, b) => a.weekday - b.weekday);
  if (open.length === 0) {
    return 'Closed every day';
  }
  return open.map((s) => `${WEEKDAY_NAMES[s.weekday]} ${s.start_time}–${s.end_time}`).join(', ');
});

// Optional rows only show when there's something to show. The
// Date/Availability row needs the flow to have actually collected it:
// Add always does; Edit passes no `occurrence` for an event (its dates are
// managed on the Dates step, not accumulated here), so the row drops out
// rather than reading "—".
const rows = computed(() => {
  const d = props.data;
  const isEvent = 'event' === d.type;
  const hasAvailability = isEvent ? Boolean(d.occurrence) : Array.isArray(d.schedules);
  const list = [
    { label: 'Type', value: TYPE_LABELS[d.type] || d.type },
    { label: 'Name', value: d.name },
  ];
  if (!isEvent) {
    list.push({ label: 'Duration', value: `${d.duration_minutes} minutes` });
  }
  if ('appointment' !== d.type) {
    list.push({ label: 'Capacity', value: d.capacity });
  }
  if (hasAvailability) {
    list.push({ label: isEvent ? 'Date' : 'Availability', value: availabilitySummary.value });
  }
  if (d.location) {
    list.push({ label: 'Location', value: d.location });
  }
  if (d.meeting_link) {
    list.push({ label: 'Meeting link', value: d.meeting_link });
  }
  list.push({ label: 'Price', value: formatMoney(d.price, { currency: d.currency, emptyLabel: 'Free' }) });
  if (d.status) {
    list.push({ label: 'Status', value: STATUS_LABELS[d.status] || d.status });
  }
  return list;
});
</script>
