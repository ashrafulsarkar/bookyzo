<template>
  <Card>
    <CardHeader>
      <CardTitle>Review</CardTitle>
      <CardDescription>Everything look right? Nothing is saved until you confirm below.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-3 text-sm">
      <div v-for="row in rows" :key="row.label" class="flex justify-between gap-6 border-b border-border pb-2 last:border-b-0 last:pb-0">
        <span class="shrink-0 text-muted-foreground">{{ row.label }}</span>
        <span class="min-w-0 break-words text-right font-medium">{{ row.value }}</span>
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

// Review for the Add/Edit popup (BookableItemWizard.vue). The setup
// wizard has its own ReviewStep.vue with the extra settings rows
// (phone / auto-confirm / notifications) this quick flow doesn't collect.
const props = defineProps({
  data: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
  submitLabel: { type: String, default: 'Create bookable item' },
  savingLabel: { type: String, default: 'Creating…' },
});
defineEmits(['create', 'back']);

const TYPE_LABELS = { appointment: 'Appointment', table: 'Table', event: 'Event', slot: 'Slot' };
const STATUS_LABELS = { active: 'Active', inactive: 'Inactive', archived: 'Archived' };
const WEEKDAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function shortDate(date) {
  const parsed = new Date(`${date}T00:00:00`);
  return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

// "3 dates: Sep 12 19:00–22:00, Sep 19 19:00–22:00, Sep 26 19:00–22:00 +2 more"
function listSummary(items, noun, render) {
  if (items.length === 0) {
    return 'None';
  }
  const shown = items.slice(0, 3).map(render).join(', ');
  const more = items.length > 3 ? ` +${items.length - 3} more` : '';
  return `${items.length} ${noun}${items.length === 1 ? '' : 's'}: ${shown}${more}`;
}

const availabilitySummary = computed(() => {
  if ('event' === props.data.type) {
    return listSummary(props.data.occurrences || [], 'date', (o) => `${shortDate(o.date)} ${o.start_time}–${o.end_time}`);
  }
  const open = (props.data.schedules || []).slice().sort((a, b) => a.weekday - b.weekday);
  if (open.length === 0) {
    return 'Closed every day';
  }
  return open.map((s) => `${WEEKDAY_NAMES[s.weekday]} ${s.start_time}–${s.end_time}`).join(', ');
});

const exceptionsSummary = computed(() =>
  listSummary(props.data.exceptions || [], 'exception', (x) => `${shortDate(x.date)} (${x.is_closed ? 'closed' : `${x.start_time}–${x.end_time}`})`)
);

const rows = computed(() => {
  const d = props.data;
  const list = [
    { label: 'Type', value: TYPE_LABELS[d.type] || d.type },
    { label: 'Name', value: d.name },
  ];
  if (d.type !== 'event') {
    list.push({ label: 'Duration', value: `${d.duration_minutes} minutes` });
  }
  if (d.type !== 'appointment') {
    list.push({ label: 'Capacity', value: String(d.capacity) });
  }
  if (d.location) {
    list.push({ label: 'Location', value: d.location });
  }
  if (d.meeting_link) {
    list.push({ label: 'Meeting link', value: d.meeting_link });
  }
  if (d.tags) {
    list.push({ label: 'Tags', value: d.tags });
  }
  list.push({ label: d.type === 'event' ? 'Dates' : 'Availability', value: availabilitySummary.value });
  if (d.type !== 'event' && Array.isArray(d.exceptions)) {
    list.push({ label: 'Exceptions', value: exceptionsSummary.value });
  }
  list.push({ label: 'Price', value: d.price ? `${d.currency} ${Number(d.price).toFixed(2)}` : 'Free' });
  if (d.status) {
    list.push({ label: 'Status', value: STATUS_LABELS[d.status] || d.status });
  }
  return list;
});
</script>
