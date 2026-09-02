<template>
  <Card>
    <CardHeader>
      <CardTitle>What do you want to book?</CardTitle>
      <CardDescription>
        Choose the type of booking your business needs. You can add more types later with Bookyzo Pro.
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
      <Button @click="$emit('next')">Continue</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

defineEmits(['next']);

// Appointment is the only type this build actually implements
// (ROADMAP.md Phase 1) — Table/Event/Slot exist in the resources.type
// schema enum for forward compatibility (DETAILED-SPEC.md §1.1) but have
// no admin screens or booking flow behind them yet, so they're not
// listed here at all. A disabled "coming soon" option would visually be
// the exact locked/greyed-out pattern the bookyzo-design skill's
// Guideline 5 rule forbids, even though this isn't a Pro-vs-Free gate —
// showing a choice with nothing behind it reads the same way to a
// reviewer (and a user) as showing a Pro tease. The radio UI still
// renders as a real (if single-option) choice, matching DETAILED-SPEC.md
// §A1's "radio, not checkbox" — this list just grows honestly as each
// type actually ships (Phase 3+), never by un-disabling a placeholder.
const types = [
  { value: 'appointment', label: 'Appointment', description: 'One-on-one bookings with staff — salons, clinics, consultants.' },
];

const selected = ref('appointment');
</script>
