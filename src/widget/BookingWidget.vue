<template>
  <div class="bookyzo-widget">
    <div class="progress">
      <span v-for="n in 4" :key="n" class="dot" :class="{ active: n <= step }" />
    </div>

    <StepResource v-if="step === 1" :config="config" @selected="onResourceSelected" />
    <StepSlot v-else-if="step === 2" :resource="selectedResource" @selected="onSlotSelected" @back="step = 1" />
    <StepDetails
      v-else-if="step === 3"
      :submitting="submitting"
      :error="error"
      @submit="onDetailsSubmit"
      @back="step = 2"
    />
    <StepConfirmation v-else-if="step === 4" :booking="createdBooking" :resource="selectedResource" />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import StepResource from './steps/StepResource.vue';
import StepSlot from './steps/StepSlot.vue';
import StepDetails from './steps/StepDetails.vue';
import StepConfirmation from './steps/StepConfirmation.vue';
import { createBooking } from './api';

// 4-step flow per DETAILED-SPEC.md §A5: resource/service picker -> slot
// picker -> contact form -> confirmation. One SPA-like component tree,
// no page reloads between steps.
const props = defineProps({
  config: { type: Object, required: true },
});

const step = ref(1);
const selectedResource = ref(null);
const selectedSlot = ref(null);
const submitting = ref(false);
const error = ref('');
const createdBooking = ref(null);

function onResourceSelected(resource) {
  selectedResource.value = resource;
  step.value = 2;
}

function onSlotSelected(slot) {
  selectedSlot.value = slot;
  step.value = 3;
}

async function onDetailsSubmit(form) {
  error.value = '';
  submitting.value = true;
  try {
    const booking = await createBooking({
      slot_id: selectedSlot.value.id,
      customer_name: form.name,
      customer_email: form.email,
      customer_phone: form.phone,
      customer_notes: form.notes,
      // Honeypot — see BYZO_Public_Guard. Always empty for a real
      // visitor (the field is visually hidden in StepDetails.vue).
      website: form.website,
    });
    createdBooking.value = booking;
    step.value = 4;
  } catch (e) {
    error.value = e.message;
  } finally {
    submitting.value = false;
  }
}
</script>
