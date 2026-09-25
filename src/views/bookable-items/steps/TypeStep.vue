<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ locked ? __('Booking type', 'bookyzo') : __('What are you adding?', 'bookyzo') }}</CardTitle>
      <CardDescription>
        {{ locked ? __("The booking type can't be changed after creation — its schedule and slots are built for this type.", 'bookyzo') : __('Choose what this bookable item is.', 'bookyzo') }}
      </CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-3">
      <label
        v-for="type in types"
        :key="type.value"
        :class="[
          'flex items-start gap-3 rounded-lg border p-4 transition-colors',
          selected === type.value ? 'border-primary bg-accent' : 'border-border',
          locked ? (selected === type.value ? 'cursor-default' : 'cursor-not-allowed opacity-50') : 'cursor-pointer hover:border-primary',
        ]"
      >
        <!-- h-5 matches the title's line box below, so the control centres
             on the FIRST line of the label instead of being nudged down by
             a hand-tuned margin (which wp-admin's own radio margin used to
             out-specify anyway — see styles/tailwind.css). -->
        <span class="flex h-5 shrink-0 items-center">
          <input
            type="radio"
            name="bookable_item_type"
            :value="type.value"
            :disabled="locked && selected !== type.value"
            v-model="selected"
          />
        </span>
        <span>
          <span class="block font-medium leading-5">{{ type.label }}</span>
          <span class="block text-sm text-muted-foreground">{{ type.description }}</span>
        </span>
      </label>
    </CardContent>
    <CardFooter class="justify-end">
      <Button @click="$emit('selected', selected)">{{ __('Continue', 'bookyzo') }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

const props = defineProps({
  // The type picked on an earlier pass (or the item's type, on edit). The
  // parent wizard v-if-mounts each step, so without this a Back from
  // Details remounted the picker on its 'appointment' default and the
  // owner's choice looked lost.
  initial: { type: String, default: '' },
  // Edit: the step still shows (an edit is the same step-by-step flow as
  // an add) but only the item's own type is selectable — changing type
  // would orphan its schedule/slots, and update_item() rejects it anyway
  // (DETAILED-SPEC.md §5 item 6).
  locked: { type: Boolean, default: false },
});
defineEmits(['selected']);

// Same 4 options as the setup wizard's BookingTypeStep.vue and the block
// editor's type selector — kept in sync by hand across the 3 pickers.
// An Event item gets a dated-occurrence list instead of a weekly schedule
// (AvailabilityStep.vue), matching DETAILED-SPEC.md §1.3's architecture.
const types = [
  { value: 'appointment', label: i18n.__('Appointment', 'bookyzo'), description: i18n.__('One-on-one bookings with staff — salons, clinics, consultants.', 'bookyzo') },
  { value: 'table', label: i18n.__('Table', 'bookyzo'), description: i18n.__('Reservations for a table, room, or group — restaurants, venues.', 'bookyzo') },
  { value: 'event', label: i18n.__('Event', 'bookyzo'), description: i18n.__('Ticketed, dated occurrences — concerts, workshops, one-off sessions.', 'bookyzo') },
  { value: 'slot', label: i18n.__('Slot', 'bookyzo'), description: i18n.__('Fixed-capacity time slots — classes, workshops, rental sessions.', 'bookyzo') },
];

const selected = ref(types.some((t) => t.value === props.initial) ? props.initial : 'appointment');
</script>
