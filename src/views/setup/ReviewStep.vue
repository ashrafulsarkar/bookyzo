<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ __('Review', 'bookyzo') }}</CardTitle>
      <CardDescription>{{ __('Everything looks right? Create your first bookable item and finish setup.', 'bookyzo') }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-3 text-sm">
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ __('Type', 'bookyzo') }}</span>
        <span class="font-medium">{{ typeLabel }}</span>
      </div>
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ __('Name', 'bookyzo') }}</span>
        <span class="font-medium">{{ data.name }}</span>
      </div>
      <div v-if="data.type !== 'event'" class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ __('Duration', 'bookyzo') }}</span>
        <span class="font-medium">{{ sprintf(/*! translators: %s: number of minutes. */ _n('%s minute', '%s minutes', data.duration_minutes, 'bookyzo'), data.duration_minutes) }}</span>
      </div>
      <div v-if="data.type !== 'appointment'" class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ __('Capacity', 'bookyzo') }}</span>
        <span class="font-medium">{{ data.capacity }}</span>
      </div>
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ data.type === 'event' ? __('Dates', 'bookyzo') : __('Availability', 'bookyzo') }}</span>
        <span class="font-medium">{{ availabilitySummary }}</span>
      </div>
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ __('Price', 'bookyzo') }}</span>
        <span class="font-medium">{{ formatMoney(data.price, { currency: data.currency, emptyLabel: __('Free', 'bookyzo') }) }}</span>
      </div>
      <div v-if="data.location" class="flex justify-between gap-4 border-b border-border pb-2">
        <span class="shrink-0 text-muted-foreground">{{ __('Location', 'bookyzo') }}</span>
        <span class="break-words text-right font-medium">{{ data.location }}</span>
      </div>
      <div v-if="data.meeting_link" class="flex justify-between gap-4 border-b border-border pb-2">
        <span class="shrink-0 text-muted-foreground">{{ __('Meeting link', 'bookyzo') }}</span>
        <span class="break-words text-right font-medium">{{ data.meeting_link }}</span>
      </div>
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ __('Phone required', 'bookyzo') }}</span>
        <span class="font-medium">{{ data.require_phone ? __('Yes', 'bookyzo') : __('No', 'bookyzo') }}</span>
      </div>
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ __('New bookings are', 'bookyzo') }}</span>
        <span class="font-medium">{{ data.auto_confirm ? __('Auto-confirmed', 'bookyzo') : __('Pending review', 'bookyzo') }}</span>
      </div>
      <div class="flex justify-between">
        <span class="text-muted-foreground">{{ __('Notifications', 'bookyzo') }}</span>
        <span class="font-medium">{{ notificationsSummary }}</span>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" :disabled="saving" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</Button>
      <Button :disabled="saving" @click="$emit('create')">{{ saving ? __('Creating…', 'bookyzo') : __('Create & finish', 'bookyzo') }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { weekdayNames } from '@/lib/date';
import { i18n } from '@/lib/i18n';
import { computed } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';

const props = defineProps({
  data: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['create', 'back']);

const TYPE_LABELS = { appointment: i18n.__('Appointment', 'bookyzo'), table: i18n.__('Table', 'bookyzo'), event: i18n.__('Event', 'bookyzo'), slot: i18n.__('Slot', 'bookyzo') };
const typeLabel = computed(() => TYPE_LABELS[props.data.type] || props.data.type);

const WEEKDAY_NAMES = weekdayNames('short');

const availabilitySummary = computed(() => {
  if ('event' === props.data.type) {
    const occurrences = props.data.occurrences || [];
    if (occurrences.length === 0) {
      return '—';
    }
    const shown = occurrences.slice(0, 3).map((o) => `${o.date} ${o.start_time}–${o.end_time}`).join(', ');
    return occurrences.length > 3 ? `${shown} ` + i18n.sprintf(/*! translators: %s: number of further items not shown. */ i18n.__('+%s more', 'bookyzo'), occurrences.length - 3) : shown;
  }

  const open = (props.data.schedules || []).slice().sort((a, b) => a.weekday - b.weekday);
  if (open.length === 0) {
    return i18n.__('Closed every day', 'bookyzo');
  }
  return open.map((s) => `${WEEKDAY_NAMES[s.weekday]} ${s.start_time}–${s.end_time}`).join(', ');
});

const notificationsSummary = computed(() => {
  const parts = [];
  if (props.data.send_confirmation_email) parts.push(i18n.__('customer confirmation', 'bookyzo'));
  if (props.data.send_admin_notification) parts.push(i18n.__('admin new-booking alert', 'bookyzo'));
  return parts.length > 0 ? parts.join(' + ') : i18n.__('None', 'bookyzo');
});
</script>
