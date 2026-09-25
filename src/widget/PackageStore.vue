<template>
  <div class="bookyzo-widget bookyzo-store">
    <WidgetLoading v-if="loading" />
    <p v-else-if="loadError" class="error">{{ loadError }}</p>
    <p v-else-if="!packages.length" class="empty">No packages are on sale right now.</p>

    <!-- 1. Pick a package -->
    <template v-else-if="step === 'pick'">
      <h2>Buy a package</h2>
      <p class="step-sub">Pay once, then book with your package code instead of paying each time.</p>
      <div class="store-list">
        <button v-for="pkg in packages" :key="pkg.id" type="button" class="option-card" @click="choose(pkg)">
          <span class="option-card-body">
            <span class="name">{{ pkg.name }}</span>
            <span v-if="pkg.description" class="desc">{{ pkg.description }}</span>
            <span class="meta">
              <span class="meta-item">{{ pkg.credits }} {{ pkg.credits === 1 ? 'booking' : 'bookings' }}</span>
              <span class="meta-item">{{ pkg.applies_label }}</span>
              <span v-if="pkg.validity_days" class="meta-item">Valid {{ pkg.validity_days }} days</span>
            </span>
          </span>
          <span class="option-card-price">{{ money(pkg.price) }}</span>
        </button>
      </div>
    </template>

    <!-- 2. Who it's for -->
    <template v-else-if="step === 'details'">
      <h2>{{ chosen.name }}</h2>
      <p class="step-sub">{{ chosen.credits }} {{ chosen.credits === 1 ? 'booking' : 'bookings' }} · {{ money(chosen.price) }}</p>
      <div class="field">
        <label for="bs-name">Name</label>
        <input id="bs-name" v-model="form.customer_name" type="text" autocomplete="name" />
      </div>
      <div class="field">
        <label for="bs-email">Email</label>
        <input id="bs-email" v-model="form.customer_email" type="email" autocomplete="email" />
        <span class="hint">Your package code is sent here.</span>
      </div>
      <div class="honeypot" aria-hidden="true">
        <label for="bs-website">Website</label>
        <input id="bs-website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <div class="actions">
        <button type="button" class="btn btn-outline" :disabled="busy" @click="step = 'pick'">Back</button>
        <button type="button" class="btn btn-primary" :disabled="busy || !canContinue" @click="start">{{ busy ? 'One moment…' : chosen.price > 0 ? 'Continue to payment' : 'Get it' }}</button>
      </div>
    </template>

    <!-- 3. Pay -->
    <template v-else-if="step === 'pay'">
      <h2>Payment</h2>
      <p class="step-sub">{{ chosen.name }} · {{ money(chosen.price) }}</p>
      <WidgetLoading v-if="loadingStripe" label="Loading payment form…" />
      <p v-else-if="setupError" class="error">{{ setupError }}</p>
      <div v-else ref="cardRef" class="field card-field"></div>
      <p v-if="error" class="error">{{ error }}</p>
      <div class="actions">
        <button type="button" class="btn btn-primary" :disabled="!cardReady || busy" @click="pay">{{ busy ? 'Processing…' : `Pay ${money(chosen.price)}` }}</button>
      </div>
    </template>

    <!-- 4. Done -->
    <div v-else-if="step === 'done'" class="store-done">
      <h2>You're all set</h2>
      <p>Your code for <strong>{{ chosen.name }}</strong>:</p>
      <p class="store-code">{{ purchase.code }}</p>
      <p class="step-sub">
        Enter it in "Discount or package code" when you book. Each person you book uses one of your {{ chosen.credits }}.
        <template v-if="purchase.expires_at"> Valid until {{ formatDate(purchase.expires_at) }}.</template>
        We've emailed it to {{ form.customer_email }} as well.
      </p>
    </div>
  </div>
</template>

<script setup>
// Package store (QA report 2026-09-22 §6.1 A2, user decision 2026-09-25:
// customers buy packages online). Rendered only where an add-on's
// shortcode put a .bookyzo-packages-mount; the routes are the add-on's.
// Card payment works like StepPayment.vue: Stripe.js from Stripe's CDN,
// then the server checks the intent with Stripe before issuing the code.
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { getPackages, startPackagePurchase, createPackageIntent, confirmPackagePurchase } from './api';
import WidgetLoading from './WidgetLoading.vue';

const currency = (window.byzoWidget && window.byzoWidget.currency) || 'USD';
const money = (value) => {
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(value) || 0);
  } catch {
    return `${currency} ${(Number(value) || 0).toFixed(2)}`;
  }
};
const formatDate = (value) => new Date(String(value).replace(' ', 'T')).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });

const known = (window.byzoWidget && window.byzoWidget.currentCustomer) || {};
const packages = ref([]);
const loading = ref(true);
const loadError = ref('');
const step = ref('pick');
const chosen = ref(null);
const form = ref({ customer_name: known.name || '', customer_email: known.email || '', website: '' });
const purchase = ref(null);
const busy = ref(false);
const error = ref('');

const loadingStripe = ref(true);
const setupError = ref('');
const cardReady = ref(false);
const cardRef = ref(null);
let stripe = null;
let card = null;

const canContinue = computed(() => form.value.customer_name.trim() && /\S+@\S+\.\S+/.test(form.value.customer_email));

function choose(pkg) {
  chosen.value = pkg;
  error.value = '';
  step.value = 'details';
}

async function start() {
  busy.value = true;
  error.value = '';
  try {
    purchase.value = await startPackagePurchase(chosen.value.id, form.value);
    if (purchase.value.status === 'paid') {
      step.value = 'done';
      return;
    }
    step.value = 'pay';
    await setUpCard();
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

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

async function setUpCard() {
  const key = window.byzoWidget && window.byzoWidget.stripePublishableKey;
  if (!key) {
    setupError.value = 'Online payment is not available right now.';
    loadingStripe.value = false;
    return;
  }
  try {
    const Stripe = await loadStripeJs();
    stripe = Stripe(key);
    card = stripe.elements().create('card');
    loadingStripe.value = false;
    await nextTick();
    card.mount(cardRef.value);
    card.on('change', (event) => {
      cardReady.value = event.complete;
      error.value = event.error ? event.error.message : '';
    });
  } catch (e) {
    setupError.value = e.message;
    loadingStripe.value = false;
  }
}

async function pay() {
  busy.value = true;
  error.value = '';
  try {
    const { client_secret: clientSecret } = await createPackageIntent(purchase.value.id, purchase.value.token);
    const result = await stripe.confirmCardPayment(clientSecret, { payment_method: { card } });
    if (result.error) {
      error.value = result.error.message;
      return;
    }
    purchase.value = await confirmPackagePurchase(purchase.value.id, purchase.value.token, result.paymentIntent.id);
    step.value = 'done';
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  try {
    packages.value = await getPackages();
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
  }
});

onBeforeUnmount(() => card?.destroy());
</script>
