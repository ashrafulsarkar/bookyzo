<template>
  <div>
    <h2>Payment</h2>
    <p v-if="isDeposit">
      {{ booking.customer_name }}, a deposit of {{ formattedAmount }} is due now to secure your booking — the
      remaining {{ formattedBalance }} of your {{ formattedTotal }} total is due at the appointment.
    </p>
    <p v-else>{{ booking.customer_name }}, your total is {{ formattedAmount }}.</p>

    <WidgetLoading v-if="loadingStripe" label="Loading payment form…" />
    <div v-else-if="setupError" class="error">{{ setupError }}</div>
    <template v-else>
      <div ref="cardElementRef" class="field" style="border: 1px solid var(--byzo-border); border-radius: var(--byzo-radius-sm); padding: 10px"></div>
      <p v-if="payError" class="error">{{ payError }}</p>
    </template>

    <div class="actions">
      <button v-if="payInPersonEnabled" type="button" class="btn btn-outline" @click="$emit('skip')">Pay in person instead</button>
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
// it. "Pay in person instead" stays available by default (matches the
// manual/offline payment option DETAILED-SPEC.md §A7 already calls
// for) — this step is not a hard gate on completing a booking UNLESS the
// merchant explicitly turns it off via the Settings screen's "Booking
// behavior" section (BYZO_Settings::get()['allow_pay_in_person'],
// payInPersonEnabled below), added 2026-09-22 because "Pay in person"
// and "Pay ${amount}" sitting as equal-weight buttons let a customer
// bypass online payment even on a site whose owner wants to require it.
//
// Verified end-to-end against Stripe test-mode keys 2026-09-11 (see
// BYZO_Stripe's own docblock).
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { createBookingPaymentIntent, confirmBookingPayment } from '../api';
import WidgetLoading from '../WidgetLoading.vue';

const payInPersonEnabled = !(window.byzoWidget && window.byzoWidget.payInPersonEnabled === false);

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
const formatCurrency = (value) => new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(value || 0);

// Phase 2 step 6 (DETAILED-SPEC.md §A7) — deposit_amount is null for
// every booking made the way Free itself works; when Pro sets it, this
// is what's ACTUALLY charged now (matches BYZO_Stripe::create_payment_intent()'s
// own deposit_amount ?? payment_amount resolution exactly — the amount
// shown here must never disagree with what the card is really charged).
const isDeposit = props.booking.deposit_amount !== null && props.booking.deposit_amount !== undefined && props.booking.deposit_amount < props.booking.payment_amount;
const formattedAmount = formatCurrency(isDeposit ? props.booking.deposit_amount : props.booking.payment_amount);
const formattedTotal = formatCurrency(props.booking.payment_amount);
const formattedBalance = formatCurrency(isDeposit ? props.booking.payment_amount - props.booking.deposit_amount : 0);

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
      return;
    }

    // Tell the server the charge cleared. The Stripe webhook does this
    // too, but it can't reach every site (local/staging, firewalled
    // hosts, webhook not configured yet) and the booking would sit
    // `unpaid` forever. A failure here is deliberately NOT surfaced as a
    // payment error: the money has already been taken, so blocking the
    // confirmation screen would be worse than a booking the webhook or
    // the site owner reconciles later.
    try {
      await confirmBookingPayment(
        props.booking.id,
        props.booking.customer_email,
        result.paymentIntent.id
      );
    } catch (e) {
      // Intentionally swallowed — see above.
    }

    emit('paid');
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

    // The mount target lives inside `v-else`, so it does not exist in the
    // DOM while loadingStripe is true. Clear the flag and wait for the
    // re-render BEFORE mounting — calling mount() first passes Stripe a
    // null element and it throws "Missing argument. Make sure to call
    // mount() with a valid DOM element or selector."
    loadingStripe.value = false;
    await nextTick();

    if (!cardElementRef.value) {
      throw new Error('Could not load the payment form.');
    }

    cardElement.mount(cardElementRef.value);
    cardElement.on('change', (event) => {
      cardReady.value = event.complete;
      payError.value = event.error ? event.error.message : '';
    });
  } catch (e) {
    setupError.value = e.message;
    loadingStripe.value = false;
  }
});

// Stripe mounts an iframe into our div; without this it stays registered
// with the Elements instance after the step is left ("Pay in person
// instead", or going back), which Stripe warns about on a re-mount.
onBeforeUnmount(() => {
  cardElement?.destroy();
});
</script>
