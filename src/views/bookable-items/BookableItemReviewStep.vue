<template>
  <Card>
    <CardHeader>
      <CardTitle>Review</CardTitle>
      <CardDescription>Everything look right? Create this bookable item.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-3 text-sm">
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">Type</span>
        <span class="font-medium">{{ typeLabel }}</span>
      </div>
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">Name</span>
        <span class="font-medium">{{ data.name }}</span>
      </div>
      <div v-if="data.type !== 'event'" class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">Duration</span>
        <span class="font-medium">{{ data.duration_minutes }} minutes</span>
      </div>
      <div v-if="data.type !== 'appointment'" class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">Capacity</span>
        <span class="font-medium">{{ data.capacity }}</span>
      </div>
      <div class="flex justify-between border-b border-border pb-2">
        <span class="text-muted-foreground">{{ data.type === 'event' ? 'Date' : 'Availability' }}</span>
        <span class="font-medium">{{ availabilitySummary }}</span>
      </div>
      <div class="flex justify-between">
        <span class="text-muted-foreground">Price</span>
        <span class="font-medium">{{ data.price ? `${data.currency} ${Number(data.price).toFixed(2)}` : 'Free' }}</span>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" :disabled="saving" @click="$emit('back')">Back</Button>
      <Button :disabled="saving" @click="$emit('create')">{{ saving ? 'Creating…' : 'Create bookable item' }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { computed } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

const props = defineProps({
  data: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['create', 'back']);

// Same field matrix as the setup wizard's own ReviewStep.vue, minus the
// phone/auto-confirm/notification fields — this quick-add popup doesn't
// collect those, they stay editable from Settings like they always were.
const TYPE_LABELS = { appointment: 'Appointment', table: 'Table', event: 'Event', slot: 'Slot' };
const typeLabel = computed(() => TYPE_LABELS[props.data.type] || props.data.type);

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
</script>
