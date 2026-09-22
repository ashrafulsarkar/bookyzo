<template>
  <div>
    <h2>Checkout</h2>
    <WidgetLoading v-if="!error" label="Taking you to secure checkout…" />
    <template v-else>
      <p class="error">{{ error }}</p>
      <div class="actions">
        <button type="button" class="btn btn-outline" @click="payInPerson">Pay in person instead</button>
      </div>
    </template>
  </div>
</template>

<script setup>
// WooCommerce Bridge checkout hand-off (DETAILED-SPEC.md §3 B9). Only
// reached when the selected item's wc_checkout_enabled flag is true (the
// separate bookyzo-woocommerce plugin is active + licensed + enabled for
// this item) — the booking itself already exists (status pending,
// capacity already claimed, exactly like any other booking) by the time
// this step mounts; this call only adds it to the WooCommerce cart and
// hands back the checkout URL to redirect to. A full page navigation
// (not an SPA route) is correct here — WooCommerce's own checkout is a
// real page WordPress renders, not something this widget could render
// inline even if it wanted to.
//
// Its own "Pay in person instead" below is deliberately NOT gated by the
// Payments screen's payInPersonEnabled flag (unlike StepPayment.vue's) —
// it only ever appears when starting the WC checkout hand-off itself
// FAILED (see the v-else branch), i.e. as error recovery for a broken
// integration, not as a customer's deliberate choice to skip payment. A
// merchant requiring online payment still needs the WC checkout to
// actually reach WooCommerce, not to lose their customer's already-placed
// booking to a dead end.
import { onMounted, ref } from 'vue';
import { startWooCommerceCheckout, setBookingPaymentMethod } from '../api';
import WidgetLoading from '../WidgetLoading.vue';

const props = defineProps({
  booking: { type: Object, required: true },
});
const emit = defineEmits(['skip']);

const error = ref('');

// Refused server-side when the merchant turned "Pay in person" off —
// swallowed either way, this button is error recovery (see above).
async function payInPerson() {
  await setBookingPaymentMethod(props.booking.id, props.booking.customer_email, 'in_person').catch(() => {});
  emit('skip');
}

onMounted(async () => {
  try {
    const { checkout_url: checkoutUrl } = await startWooCommerceCheckout(props.booking.id, props.booking.customer_email);
    window.location.href = checkoutUrl;
  } catch (e) {
    error.value = e.message || 'Could not start checkout. Please try again.';
  }
});
</script>
