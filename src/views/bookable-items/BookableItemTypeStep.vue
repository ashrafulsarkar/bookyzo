<template>
  <Card>
    <CardHeader>
      <CardTitle>What are you adding?</CardTitle>
      <CardDescription>Choose what this bookable item is.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-3">
      <label
        v-for="type in types"
        :key="type.value"
        :class="[
          'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors hover:border-primary',
          selected === type.value ? 'border-primary bg-accent' : 'border-border',
        ]"
      >
        <input type="radio" name="bookable_item_type" class="mt-1" :value="type.value" v-model="selected" />
        <span>
          <span class="block font-medium">{{ type.label }}</span>
          <span class="block text-sm text-muted-foreground">{{ type.description }}</span>
        </span>
      </label>
    </CardContent>
    <CardFooter class="justify-end">
      <Button @click="$emit('selected', selected)">Continue</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

defineEmits(['selected']);

// Same radio-card pattern as the setup wizard's BookingTypeStep.vue
// (DETAILED-SPEC.md §A2's "reuse the wizard's 4-option card UI"), but
// deliberately still 3 options here, not 4: Event needs its own
// occurrence-based "Dates" tab and a slot-generator that skips it
// entirely (ROADMAP.md Phase 1.5 step 5, not built yet) — creating an
// Event-type item today would fall through to the Availability/Exceptions
// tabs and get real weekly-recurring slots generated for it, which
// directly violates the decided architecture (DETAILED-SPEC.md §1.3:
// "Event-type items bypass the generator entirely... never wire
// event items into the weekly-schedule path"). Appointment/Table/Slot
// all safely share that same weekly-schedule engine today, so those three
// are real, functional choices — Event is added here once step 5 lands,
// mirroring BookingTypeStep.vue's own "don't list a choice with nothing
// behind it" rule.
const types = [
  { value: 'appointment', label: 'Appointment', description: 'One-on-one bookings with staff — salons, clinics, consultants.' },
  { value: 'table', label: 'Table', description: 'Reservations for a table, room, or group — restaurants, venues.' },
  { value: 'slot', label: 'Slot', description: 'Fixed-capacity time slots — classes, workshops, rental sessions.' },
];

const selected = ref('appointment');
</script>
