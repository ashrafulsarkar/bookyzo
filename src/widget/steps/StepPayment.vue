<template>
  <div>
    <h2>Payment</h2>
    <p>{{ booking.customer_name }}, your total is {{ formattedAmount }}.</p>

    <div v-if="loadingStripe" class="empty">Loading payment form…</div>
    <div v-else-if="setupError" class="error">{{ setupError }}</div>
    <template v-else>
      <div ref="cardElementRef" class="field" style="border: 1px solid var(--byzo-border); border-radius: var(--byzo-radius-sm); padding: 10px"></div>
      <p v-if="payError" class="error">{{ payError }}</p>
    </template>

    <div class="actions">
      <button type="button" class="btn btn-outline" @click="$emit('skip')">Pay in person instead</button>
      <button type="button" class="btn btn-primary" :disabled="!cardReady || paying" @click="pay">
        {{ paying ? 'Processing…' : `Pay ${formattedAmount}` }}
      </button>
    </div>
  </div>
</template>

<script setup>
// Stripe Elements card payment (DETAILED-SPEC.md §A7) — loads Stripe.js
// from Stripe's own CDN (js.stripe.com), which is required rather than
// optional: Stripe's terms don't allow self-hosting/bundling this
// script, and doing so would break PCI-DSS compliance for anyone using
// it. "Pay in person instead" always stays available (matches the
// manual/offline payment option DETAILED-SPEC.md §A7 already calls
// for) — this step is never a hard gate on completing a booking.
//
// NOT independently verified against a live Stripe account in this
// session (no test-mode API keys available) — see BYZO_Stripe's own
// docblock for the same caveat on the backend half of this flow.
import { ref, onMounted } from 'vue';
import { createBookingPaymentIntent } from '../api';

const props = defineProps({
  booking: { type: Object, required: true },
});
const emit = defineEmits(['paid', 'skip']);

const loadingStripe = ref(true);
const setupError = ref('');
const payError = ref('');
const paying = ref(false);
const cardReady = ref(false);
// A template ref (unique per component instance), not a literal DOM id —
// main.js mounts one BookingWidget instance per .bookyzo-widget-mount
// element, so a hardcoded id="..." here would collide if two widget
// embeds on the same page both reached this step at once.
const cardElementRef = ref(null);

let stripe = null;
let elements = null;
let cardElement = null;

const currency = (window.byzoWidget && window.byzoWidget.currency) || 'USD';
const formattedAmount = new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(props.booking.payment_amount || 0);

function loadStripeJs() {
  return new Promise((resolve, reject) => {
    if (window.Stripe) {
      resolve(window.Stripe);
      return;
    }
    const script = document.createElement('script');
    script.src = 'https://js.stripe.com/v3/';
    script.onload = () => resolve(window.Stripe);
    script.onerror = () => reject(new Error('Could not load the payment form.'));
    document.head.appendChild(script);
  });
}

async function pay() {
  payError.value = '';
  paying.value = true;
  try {
    const { client_secret: clientSecret } = await createBookingPaymentIntent(props.booking.id, props.booking.customer_email);
    const result = await stripe.confirmCardPayment(clientSecret, {
      payment_method: { card: cardElement },
    });
    if (result.error) {
      payError.value = result.error.message;
    } else {
      emit('paid');
    }
  } catch (e) {
    payError.value = e.message;
  } finally {
    paying.value = false;
  }
}

onMounted(async () => {
  const publishableKey = window.byzoWidget && window.byzoWidget.stripePublishableKey;
  if (!publishableKey) {
    setupError.value = 'Online payment is not available right now.';
    loadingStripe.value = false;
    return;
  }

  try {
    const Stripe = await loadStripeJs();
    stripe = Stripe(publishableKey);
    elements = stripe.elements();
    cardElement = elements.create('card');
    cardElement.mount(cardElementRef.value);
    cardElement.on('change', (event) => {
      cardReady.value = event.complete;
      payError.value = event.error ? event.error.message : '';
    });
  } catch (e) {
    setupError.value = e.message;
  } finally {
    loadingStripe.value = false;
  }
});
</script>
