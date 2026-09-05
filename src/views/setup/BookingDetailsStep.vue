<template>
  <Card>
    <CardHeader>
      <CardTitle>Tell us about your first {{ typeLabel.toLowerCase() }}</CardTitle>
      <CardDescription>You can add photos, tags, and more later.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <div class="flex flex-col gap-1.5">
        <Label for="wizard-item-name">Name</Label>
        <Input id="wizard-item-name" v-model="form.name" type="text" />
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="wizard-item-description">Description (optional)</Label>
        <Textarea id="wizard-item-description" v-model="form.description" rows="2" />
      </div>

      <!-- Hidden for Event — its duration comes from each occurrence's own
           end − start on the next step, not one fixed value here
           (DETAILED-SPEC.md §A2/A3 field matrix). -->
      <div v-if="type !== 'event'" class="flex flex-col gap-1.5">
        <Label for="wizard-item-duration">{{ durationLabel }}</Label>
        <Input id="wizard-item-duration" v-model.number="form.duration_minutes" type="number" min="5" step="5" />
      </div>

      <!-- Hidden/fixed at 1 for Appointment, same as the Bookable Items
           edit screen's own Details tab. -->
      <div v-if="type !== 'appointment'" class="flex flex-col gap-1.5">
        <Label for="wizard-item-capacity">{{ capacityLabel }}</Label>
        <Input id="wizard-item-capacity" v-model.number="form.capacity" type="number" min="1" step="1" />
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">Back</Button>
      <Button @click="continueStep">Continue</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps({
  type: { type: String, required: true },
});
const emit = defineEmits(['next', 'back']);

// Same per-type labels as BookableItemDetailsTab.vue (DETAILED-SPEC.md
// §A2/A3 field matrix) — kept in sync by hand, same duplication note as
// BookingTypeStep.vue's options list.
const TYPE_LABELS = { appointment: 'Appointment', table: 'Table', event: 'Event', slot: 'Slot' };
const typeLabel = computed(() => TYPE_LABELS[props.type] || props.type);

const DURATION_LABELS = { appointment: 'Appointment length (minutes)', table: 'Reservation length (minutes)', slot: 'Slot length (minutes)' };
const durationLabel = computed(() => DURATION_LABELS[props.type] || 'Duration (minutes)');

const CAPACITY_LABELS = { table: 'Seats', event: 'Total tickets/seats', slot: 'Spots per slot' };
const capacityLabel = computed(() => CAPACITY_LABELS[props.type] || 'Capacity');

// "Staff Member 1" matches DETAILED-SPEC.md §A1's per-type sensible-default
// convention (the same naming FirstBookableItemStep used for Appointment).
const DEFAULT_NAMES = { appointment: 'Staff Member 1', table: 'Table 1', event: 'My Event', slot: 'Bookable Item 1' };

const form = ref({
  name: DEFAULT_NAMES[props.type] || '',
  description: '',
  duration_minutes: 30,
  capacity: 1,
});
const error = ref('');

function continueStep() {
  error.value = '';
  if (!form.value.name.trim()) {
    error.value = 'Please enter a name.';
    return;
  }
  emit('next', { ...form.value });
}
</script>
