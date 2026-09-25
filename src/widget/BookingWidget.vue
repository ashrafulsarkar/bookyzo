<template>
  <div class="bookyzo-widget">
    <div class="progress">
      <span v-for="n in dotCount" :key="n" class="dot" :class="{ active: n <= displayStep }" />
    </div>

    <StepBookableItem v-if="step === 1" :config="config" @selected="onItemSelected" @selected-service="onServiceSelected" />
    <!-- step 1.5: who, after a Pro service was picked (QA report 2026-09-22
         B9) — part of the first dot, like the payment detour below. -->
    <StepStaff v-else-if="step === 1.5" :service="selectedService" :items="publicItems" @selected="onItemSelected" @back="step = 1" />
    <StepSlot v-else-if="step === 2" :item="selectedItem" @selected="onSlotSelected" @back="step = selectedItem.service_id ? 1.5 : 1" />
    <StepDetails
      v-else-if="step === 3"
      :item="selectedItem"
      :slot="selectedSlot"
      :party-size="partySize"
      :submitting="submitting"
      :error="error"
      @submit="onDetailsSubmit"
      @back="step = 2"
    />
    <!-- step 3.5: optional card payment, between "details submitted" and
         "confirmation" — only shown when Stripe is enabled AND this
         booking actually has something to charge (DETAILED-SPEC.md §A7:
         a NULL-price item has nothing to pay for at all). Using 3.5
         (not a separate numbered step) keeps the progress indicator's
         `n <= displayStep` check lighting the second-to-last dot correctly
         during this step, with the final dot lighting only once payment is
         done/skipped and confirmation is reached — no dedicated extra dot
         needed for what's an optional detour, not a required step. -->
    <StepPayment v-else-if="step === 3.5" :booking="createdBooking" @paid="step = 4" @skip="step = 4" />
    <!-- step 3.6: WooCommerce Bridge checkout hand-off (DETAILED-SPEC.md
         §3 B9) — takes priority over Stripe's own 3.5 step when the
         selected item has wc_checkout_enabled; the two are mutually
         exclusive per booking, decided in onDetailsSubmit() below. -->
    <StepWooCommerceCheckout v-else-if="step === 3.6" :booking="createdBooking" @skip="step = 4" />
    <StepConfirmation v-else-if="step === 4" :booking="createdBooking" :item="confirmedItem" />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import StepBookableItem from './steps/StepBookableItem.vue';
import StepStaff from './steps/StepStaff.vue';
import StepSlot from './steps/StepSlot.vue';
import StepDetails from './steps/StepDetails.vue';
import StepPayment from './steps/StepPayment.vue';
import StepWooCommerceCheckout from './steps/StepWooCommerceCheckout.vue';
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
const partySize = ref(null);
const submitting = ref(false);
const error = ref('');
const createdBooking = ref(null);
const selectedService = ref(null);
const publicItems = ref([]);

// Set when StepBookableItem never actually renders a picker for the user —
// either the embed is pinned to one bookable item (config.bookableItemId)
// or there was only one active item to begin with (see that component's
// autoSkipping). Either way there was no step-1 choice to make, so the
// progress indicator drops to 3 dots instead of counting a step the
// visitor never saw.
const itemStepSkipped = ref(false);
const dotCount = computed(() => (itemStepSkipped.value ? 3 : 4));
const displayStep = computed(() => (itemStepSkipped.value ? step.value - 1 : step.value));

function onServiceSelected(service, items) {
  selectedService.value = service;
  publicItems.value = items;
  step.value = 1.5;
}

function onItemSelected(item, wasAutoSkipped) {
  selectedItem.value = item;
  itemStepSkipped.value = !!wasAutoSkipped;
  step.value = 2;
}

// partySize is only supplied by the types that ask for it before times
// (Table); null leaves StepDetails to collect it as usual.
function onSlotSelected(slot, chosenPartySize) {
  selectedSlot.value = slot;
  partySize.value = chosenPartySize ?? null;
  step.value = 3;
}

// What the confirmation screen names — for "any available staff", only
// known once the server has assigned someone.
const confirmedItem = computed(() => {
  const item = selectedItem.value;
  const booking = createdBooking.value;
  if (!item || !item.service_id || item.staff_id || !booking) return item;
  const member = (item.staff || []).find((m) => m.id === booking.bookable_item_id);
  const own = publicItems.value.find((i) => i.id === booking.bookable_item_id) || {};
  return member ? { ...own, ...item, id: member.id, name: `${item.service_name} with ${member.name}` } : item;
});

async function onDetailsSubmit(form) {
  error.value = '';
  submitting.value = true;
  try {
    const booking = await createBooking({
      // A Pro service: a chosen staff member books that exact slot at the
      // service's price; "any available" sends the service and time and the
      // server assigns whoever is free (BYZO_Booking::create()).
      ...(selectedItem.value.service_id && !selectedItem.value.staff_id
        ? { service_id: selectedItem.value.service_id, start_datetime: selectedSlot.value.site_start_datetime || selectedSlot.value.start_datetime }
        : { slot_id: selectedSlot.value.id, service_id: selectedItem.value.service_id || undefined }),
      party_size: form.party_size,
      customer_name: form.name,
      customer_email: form.email,
      customer_phone: form.phone,
      customer_notes: form.notes,
      // Phase 2 step 16 (DETAILED-SPEC.md §3 B11) — Pro-defined custom
      // form field values, keyed by field id; {} when none are configured.
      custom_fields: form.custom_fields,
      // Phase 2 step 17 (DETAILED-SPEC.md §3 B6) — selected add-on ids
      // only; the server resolves the real price, never trusts a client
      // one. [] when none are configured or none were selected.
      addon_ids: form.addon_ids,
      // Phase 2 step 20 (DETAILED-SPEC.md §3 B4) — per-ticket attendee
      // names/emails; [] when the feature is off or party_size is 1.
      attendees: form.attendees,
      // Phase 2 step 12 continued (DETAILED-SPEC.md §3 B7 Event) —
      // selected ticket tier id only; the server resolves the real price
      // and atomically claims the tier's own capacity, never trusts a
      // client-supplied price. null when no tiers are configured.
      ticket_tier_id: form.ticket_tier_id,
      // Marketing consent (QA report §4.6) — only ever true when the
      // customer ticked the box; the server records it on their customer
      // row, never on the booking.
      marketing_opt_in: form.marketing_opt_in,
      discount_code: form.discount_code || undefined,
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

    const hasAmountDue = booking.payment_amount && booking.payment_amount > 0;
    // WooCommerce Bridge takes priority over Stripe when both would
    // otherwise apply (DETAILED-SPEC.md §3 B9) — an item enabled for the
    // bridge is meant to route through WC's own checkout/gateways
    // entirely, not additionally offer Bookyzo's own inline Stripe step.
    const wcCheckoutAvailable = !!(selectedItem.value && selectedItem.value.wc_checkout_enabled);
    const stripeAvailable = !!(window.byzoWidget && window.byzoWidget.stripeEnabled);
    step.value = wcCheckoutAvailable && hasAmountDue
      ? 3.6
      : stripeAvailable && hasAmountDue
        ? 3.5
        : 4;
  } catch (e) {
    error.value = e.message;
  } finally {
    submitting.value = false;
  }
}
</script>
