<template>
  <div class="bookyzo-widget">
    <div class="progress">
      <span v-for="n in 4" :key="n" class="dot" :class="{ active: n <= step }" />
    </div>

    <StepBookableItem v-if="step === 1" :config="config" @selected="onItemSelected" />
    <StepSlot v-else-if="step === 2" :item="selectedItem" @selected="onSlotSelected" @back="step = 1" />
    <StepDetails
      v-else-if="step === 3"
      :item="selectedItem"
      :slot="selectedSlot"
      :submitting="submitting"
      :error="error"
      @submit="onDetailsSubmit"
      @back="step = 2"
    />
    <!-- step 3.5: optional card payment, between "details submitted" and
         "confirmation" — only shown when Stripe is enabled AND this
         booking actually has something to charge (DETAILED-SPEC.md §A7:
         a NULL-price item has nothing to pay for at all). Using 3.5
         (not a separate numbered step) keeps the 4-dot progress
         indicator's `n <= step` check lighting dots 1-3 correctly during
         this step, with dot 4 lighting only once payment is done/skipped
         and confirmation is reached — no dedicated 5th dot needed for
         what's an optional detour, not a required step. -->
    <StepPayment v-else-if="step === 3.5" :booking="createdBooking" @paid="step = 4" @skip="step = 4" />
    <StepConfirmation v-else-if="step === 4" :booking="createdBooking" :item="selectedItem" />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import StepBookableItem from './steps/StepBookableItem.vue';
import StepSlot from './steps/StepSlot.vue';
import StepDetails from './steps/StepDetails.vue';
import StepPayment from './steps/StepPayment.vue';
import StepConfirmation from './steps/StepConfirmation.vue';
import { createBooking } from './api';

// 4-step flow per DETAILED-SPEC.md §A5: bookable-item/service picker ->
// slot picker -> contact form -> confirmation, with an optional payment
// detour (step 3.5) between details and confirmation. One SPA-like
// component tree, no page reloads between steps.
const props = defineProps({
  config: { type: Object, required: true },
});

const step = ref(1);
const selectedItem = ref(null);
const selectedSlot = ref(null);
const submitting = ref(false);
const error = ref('');
const createdBooking = ref(null);

function onItemSelected(item) {
  selectedItem.value = item;
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
      party_size: form.party_size,
      customer_name: form.name,
      customer_email: form.email,
      customer_phone: form.phone,
      customer_notes: form.notes,
      // Honeypot — see BYZO_Public_Guard. Always empty for a real
      // visitor (the field is visually hidden in StepDetails.vue).
      website: form.website,
      // Booking-time account opt-in (Phase 1.7 step 6) — only meaningful
      // when create_account is true; the server ignores password
      // otherwise. Omitted entirely (undefined) rather than sent as
      // false/'' when the accounts feature is off, since StepDetails.vue
      // never even renders the checkbox in that case.
      ...(form.create_account ? { create_account: true, password: form.password } : {}),
    });
    createdBooking.value = booking;

    const stripeAvailable = !!(window.byzoWidget && window.byzoWidget.stripeEnabled);
    const hasAmountDue = booking.payment_amount && booking.payment_amount > 0;
    step.value = stripeAvailable && hasAmountDue ? 3.5 : 4;
  } catch (e) {
    error.value = e.message;
  } finally {
    submitting.value = false;
  }
}
</script>
