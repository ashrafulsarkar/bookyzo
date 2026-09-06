<template>
  <Card>
    <CardHeader>
      <CardTitle>What do you want to accept bookings for?</CardTitle>
      <CardDescription>
        You can add more types later from Bookable Items → Add New — this just picks which one to start with.
      </CardDescription>
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
        <input type="radio" name="booking_type" class="mt-1" :value="type.value" v-model="selected" />
        <span>
          <span class="block font-medium">{{ type.label }}</span>
          <span class="block text-sm text-muted-foreground">{{ type.description }}</span>
        </span>
      </label>
    </CardContent>
    <CardFooter class="justify-end">
      <Button @click="$emit('next', selected)">Continue</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

const props = defineProps({
  // The type picked on an earlier pass, so Back from Business profile
  // re-selects it instead of snapping to Appointment (SetupWizard.vue
  // v-if-mounts each step, so local state alone doesn't survive Back).
  initial: { type: String, default: '' },
});
defineEmits(['next']);

// Same 4 real options as the Add/Edit wizard's TypeStep.vue and
// the block editor's type selector (all landed across Phase 1.5 steps
// 3-5) — kept in sync by hand across the 3 pickers, matching how
// TypeStep.vue's own comment already flags this duplication.
const types = [
  { value: 'appointment', label: 'Appointment', description: 'One-on-one bookings with staff — salons, clinics, consultants.' },
  { value: 'table', label: 'Table', description: 'Reservations for a table, room, or group — restaurants, venues.' },
  { value: 'event', label: 'Event', description: 'Ticketed, dated occurrences — concerts, workshops, one-off sessions.' },
  { value: 'slot', label: 'Slot', description: 'Fixed-capacity time slots — classes, workshops, rental sessions.' },
];

const selected = ref(types.some((t) => t.value === props.initial) ? props.initial : 'appointment');
</script>
