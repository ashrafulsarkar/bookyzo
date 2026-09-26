<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ __('Review', 'bookyzo') }}</CardTitle>
      <CardDescription>{{ __('Everything look right? Nothing is saved until you confirm below.', 'bookyzo') }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-3 text-sm">
      <div v-for="row in rows" :key="row.label" class="flex justify-between gap-6 border-b border-border pb-2 last:border-b-0 last:pb-0">
        <span class="shrink-0 text-muted-foreground">{{ row.label }}</span>
        <span class="min-w-0 break-words text-right font-medium">{{ row.value }}</span>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" :disabled="saving" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</Button>
      <Button :disabled="saving" @click="$emit('create')">{{ saving ? savingLabel : submitLabel }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { weekdayNames } from '@/lib/date';
import { i18n, locale } from '@/lib/i18n';
import { computed } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';

// Review for the Add/Edit popup (BookableItemWizard.vue). The setup
// wizard has its own ReviewStep.vue with the extra settings rows
// (phone / auto-confirm / notifications) this quick flow doesn't collect.
const props = defineProps({
  data: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
  submitLabel: { type: String, default: i18n.__('Create bookable item', 'bookyzo') },
  savingLabel: { type: String, default: i18n.__('Creating…', 'bookyzo') },
  // Rows an add-on's own step contributes (admin-extensions.js
  // extraItemEditStep.summary), listed after Price.
  extraRows: { type: Array, default: () => [] },
});
defineEmits(['create', 'back']);

const TYPE_LABELS = { appointment: i18n.__('Appointment', 'bookyzo'), table: i18n.__('Table', 'bookyzo'), event: i18n.__('Event', 'bookyzo'), slot: i18n.__('Slot', 'bookyzo') };
const STATUS_LABELS = { active: i18n.__('Active', 'bookyzo'), inactive: i18n.__('Inactive', 'bookyzo'), archived: i18n.__('Archived', 'bookyzo') };
const WEEKDAY_NAMES = weekdayNames('short');

function shortDate(date) {
  const parsed = new Date(`${date}T00:00:00`);
  return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleDateString(locale, { month: 'short', day: 'numeric' });
}

// "3 dates: Sep 12 19:00–22:00, Sep 19 19:00–22:00, Sep 26 19:00–22:00 +2 more"
function listSummary(items, noun, render) {
  if (items.length === 0) {
    return i18n.__('None', 'bookyzo');
  }
  const shown = items.slice(0, 3).map(render).join(', ');
  const more = items.length > 3 ? ' ' + i18n.sprintf(/*! translators: %s: number of further items not shown. */ i18n.__('+%s more', 'bookyzo'), items.length - 3) : '';
  return `${items.length} ${noun}${items.length === 1 ? '' : 's'}: ${shown}${more}`;
}

const availabilitySummary = computed(() => {
  if ('event' === props.data.type) {
    return listSummary(props.data.occurrences || [], 'date', (o) => `${shortDate(o.date)} ${o.start_time}–${o.end_time}`);
  }
  const open = (props.data.schedules || []).slice().sort((a, b) => a.weekday - b.weekday);
  if (open.length === 0) {
    return i18n.__('Closed every day', 'bookyzo');
  }
  return open.map((s) => `${WEEKDAY_NAMES[s.weekday]} ${s.start_time}–${s.end_time}`).join(', ');
});

const exceptionsSummary = computed(() =>
  listSummary(props.data.exceptions || [], 'exception', (x) => `${shortDate(x.date)} (${x.is_closed ? 'closed' : `${x.start_time}–${x.end_time}`})`)
);

const rows = computed(() => {
  const d = props.data;
  const list = [
    { label: i18n.__('Type', 'bookyzo'), value: TYPE_LABELS[d.type] || d.type },
    { label: i18n.__('Name', 'bookyzo'), value: d.name },
  ];
  if (d.type !== 'event') {
    list.push({ label: i18n.__('Duration', 'bookyzo'), value: i18n.sprintf(/*! translators: %s: number of minutes. */ i18n._n('%s minute', '%s minutes', d.duration_minutes, 'bookyzo'), d.duration_minutes) });
  }
  if (d.type !== 'appointment') {
    list.push({ label: i18n.__('Capacity', 'bookyzo'), value: String(d.capacity) });
  }
  if (d.location) {
    list.push({ label: i18n.__('Location', 'bookyzo'), value: d.location });
  }
  if (d.meeting_link) {
    list.push({ label: i18n.__('Meeting link', 'bookyzo'), value: d.meeting_link });
  }
  list.push({ label: d.type === 'event' ? i18n.__('Dates', 'bookyzo') : i18n.__('Availability', 'bookyzo'), value: availabilitySummary.value });
  if (d.type !== 'event' && Array.isArray(d.exceptions)) {
    list.push({ label: i18n.__('Exceptions', 'bookyzo'), value: exceptionsSummary.value });
  }
  list.push({ label: i18n.__('Price', 'bookyzo'), value: formatMoney(d.price, { currency: d.currency, emptyLabel: i18n.__('Free', 'bookyzo') }) });
  list.push(...props.extraRows);
  if (d.status) {
    list.push({ label: i18n.__('Status', 'bookyzo'), value: STATUS_LABELS[d.status] || d.status });
  }
  return list;
});
</script>
