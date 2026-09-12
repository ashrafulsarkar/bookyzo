<template>
  <div>
    <h2>{{ copy.detailsHeading }}</h2>

    <!-- What they're about to book, restated. Without it this step is a
         bare form and the chosen slot is two steps out of sight — the
         single most common place to lose confidence and abandon. -->
    <div class="summary">
      <div class="summary-row">
        <span class="summary-label">{{ copy.subjectLabel }}</span>
        <span class="summary-value">{{ item.name }}</span>
      </div>
      <div class="summary-row">
        <span class="summary-label">When</span>
        <span class="summary-value">{{ whenLabel }}</span>
      </div>
      <!-- Only when an earlier step collected it — otherwise it's an
           editable field below, not a settled fact to restate. -->
      <div v-if="partySize !== null" class="summary-row">
        <span class="summary-label">{{ partySizeLabel }}</span>
        <span class="summary-value">{{ partySize }}</span>
      </div>
      <!-- The price is on the picker two steps back and, when Stripe is
           off, nowhere after it — so the button below used to read
           "Confirm booking" with no amount anywhere on screen. Shown for
           free items too ("Free"), since silence about the price is
           exactly what makes people hesitate here. -->
      <div class="summary-row summary-row-total">
        <span class="summary-label">{{ totalLabel }}</span>
        <span class="summary-value">{{ totalValue }}</span>
      </div>
    </div>

    <div v-if="showPartySize" class="field">
      <label for="bw-party-size">{{ partySizeLabel }}</label>
      <input id="bw-party-size" v-model.number="form.party_size" type="number" min="1" :max="partySizeMax" />
    </div>

    <!-- Add-ons (Phase 2 step 17, DETAILED-SPEC.md §3 B6) — Pro-defined,
         fetched by bookable item; entirely absent when Pro is off or
         nothing's configured (addons is then just []). The server is the
         sole authority on the actual charge either way — this list is
         only a UX preview so the total shown doesn't undersell it. -->
    <div v-if="addons.length" class="field">
      <label>Add-ons <span class="optional">optional</span></label>
      <label v-for="addon in addons" :key="addon.id" class="checkbox-label addon-row">
        <span>
          <input v-model="form.addon_ids" type="checkbox" :value="addon.id" />
          {{ addon.name }}
        </span>
        <span>{{ formatMoney(addon.price) }}</span>
      </label>
    </div>

    <div class="field">
      <label for="bw-name">Name</label>
      <input id="bw-name" v-model="form.name" type="text" autocomplete="name" placeholder="Your full name" />
    </div>

    <div class="field">
      <label for="bw-email">Email</label>
      <input id="bw-email" v-model="form.email" type="email" autocomplete="email" placeholder="you@example.com" />
    </div>

    <div class="field">
      <label for="bw-phone">Phone <span class="optional">optional</span></label>
      <input id="bw-phone" v-model="form.phone" type="tel" autocomplete="tel" />
    </div>

    <div class="field">
      <label for="bw-notes">Notes <span class="optional">optional</span></label>
      <textarea id="bw-notes" v-model="form.notes" rows="2" placeholder="Anything we should know?" />
    </div>

    <!-- Booking-time account opt-in (Phase 1.7 step 6, DETAILED-SPEC.md
         §A11) — absent entirely when customer_accounts_enabled is off,
         same "absent, not disabled" rule every other capability toggle in
         this doc follows. Unchecked (the default) behaves exactly like
         today's guest booking; checked, the account is created inside the
         SAME POST /bookings call (BYZO_REST_Bookings_Controller's
         maybe_create_account_for_booking()) rather than a second round trip. -->
    <div v-if="accountsEnabled" class="field field-account-optin">
      <label class="checkbox-label">
        <input v-model="form.create_account" type="checkbox" />
        Create an account to manage this booking later
      </label>
      <input
        v-if="form.create_account"
        v-model="form.password"
        type="password"
        placeholder="Choose a password"
        autocomplete="new-password"
        minlength="8"
      />
    </div>

    <!-- Custom form fields (Phase 2 step 16, DETAILED-SPEC.md §3 B11) —
         Pro-defined, fetched by bookable item; entirely absent when Pro is
         off or nothing's configured (customFields is then just []). -->
    <div v-for="field in customFields" :key="field.id" class="field">
      <label v-if="field.field_type === 'checkbox'" class="checkbox-label">
        <input v-model="form.custom_fields[field.id]" type="checkbox" />
        {{ field.label }} <span v-if="!field.required" class="optional">optional</span>
      </label>
      <template v-else>
        <label :for="`bw-cf-${field.id}`">
          {{ field.label }} <span v-if="!field.required" class="optional">optional</span>
        </label>
        <select v-if="field.field_type === 'dropdown'" :id="`bw-cf-${field.id}`" v-model="form.custom_fields[field.id]">
          <option value="" disabled>Select…</option>
          <option v-for="choice in field.options?.choices || []" :key="choice" :value="choice">{{ choice }}</option>
        </select>
        <input
          v-else
          :id="`bw-cf-${field.id}`"
          v-model="form.custom_fields[field.id]"
          :type="{ date: 'date', number: 'number', address: 'text' }[field.field_type] || 'text'"
        />
      </template>
    </div>

    <!-- Honeypot (BYZO_Public_Guard) — visually hidden, never seen or
         filled by a real visitor, tabindex/autocomplete off so
         keyboard/autofill users can't stumble into it either. Bots that
         blanket-fill every input on a form trip this. -->
    <div class="honeypot" aria-hidden="true">
      <label for="bw-website">Website</label>
      <input id="bw-website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="actions">
      <button type="button" class="btn btn-outline" @click="$emit('back')">Back</button>
      <button type="button" class="btn btn-primary" :disabled="!canSubmit || submitting" @click="$emit('submit', form)">
        {{ submitting ? copy.submitting : copy.submit }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { reactive, computed, ref, onMounted } from 'vue';
import { copyFor } from '../copy';
import { formatMoney } from '@/lib/money';
import { getCustomFields, getAddons } from '../api';

const props = defineProps({
  item: { type: Object, required: true },
  slot: { type: Object, required: true },
  // Non-null when an earlier step already collected it (Table asks before
  // showing times, since capacity decides which times are bookable).
  partySize: { type: Number, default: null },
  submitting: { type: Boolean, default: false },
  error: { type: String, default: '' },
});

const copy = computed(() => copyFor(props.item.type));
defineEmits(['submit', 'back']);

// Party size / quantity input for capacity>1 bookable items
// (DETAILED-SPEC.md §A5) — hidden for Appointment (always 1), max = the
// chosen slot's remaining capacity. The server re-validates this
// regardless (BYZO_Booking::create()'s atomic capacity_remaining check),
// this is only a UX guide against picking a number that's already known
// to fail.
const whenLabel = computed(() => {
  const start = props.slot?.start_datetime;
  if (!start) return '';
  const date = new Date(start.replace(' ', 'T'));
  return `${date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })} at ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
});

// Hidden when the type has no quantity concept (appointment, whose
// quantityLabel is null) or when an earlier step already asked (Table).
const showPartySize = computed(() => copy.value.quantityLabel !== null && props.partySize === null);
const partySizeLabel = computed(() => copy.value.quantityLabel || 'Party size');
const partySizeMax = computed(() => Math.max(1, props.slot.capacity_remaining || 1));

// Phase 1.7 step 6 — window.byzoWidget.customerAccountsEnabled (added
// alongside restUrl/stripeEnabled/etc. by BYZO_Public_Widget::enqueue_assets()).
const accountsEnabled = Boolean(window.byzoWidget && window.byzoWidget.customerAccountsEnabled);

const form = reactive({
  party_size: props.partySize || 1,
  name: '',
  email: '',
  phone: '',
  notes: '',
  website: '',
  create_account: false,
  password: '',
  custom_fields: {},
  addon_ids: [],
});

// Phase 2 step 16 (DETAILED-SPEC.md §3 B11) — Pro-defined custom form
// fields for this bookable item. Empty when Pro is off/unconfigured, in
// which case this whole section of the form is simply absent.
const customFields = ref([]);

// Phase 2 step 17 (DETAILED-SPEC.md §3 B6) — Pro-defined add-ons for this
// bookable item. Same "empty means absent" shape as customFields above.
const addons = ref([]);

onMounted(async () => {
  try {
    const fields = await getCustomFields(props.item.id);
    customFields.value = fields;
    for (const field of fields) {
      form.custom_fields[field.id] = field.field_type === 'checkbox' ? false : '';
    }
  } catch {
    // A failed fetch here shouldn't block booking at all — the fixed
    // fields above are all a booking strictly needs; custom fields are
    // additive, so fail silently and simply show none.
    customFields.value = [];
  }

  try {
    addons.value = await getAddons(props.item.id);
  } catch {
    addons.value = [];
  }
});

// Mirrors BYZO_Booking::create()'s own arithmetic — price × quantity,
// plus the flat site-wide tax rate — so the number here is the number
// that gets charged. The server stays the only authority on the amount;
// if the two ever disagree the server wins, which is why this is a
// display-only computed and never travels back in the POST body.
const quantity = computed(() => Math.max(1, Number(props.partySize ?? form.party_size) || 1));

// Selected add-ons' prices (Phase 2 step 17) — the one part of the price
// preview that CAN be exact, since the widget already fetched real prices
// for these ids; a booking_fee/tax_rate_override live only in Pro's own
// tables and aren't fetched here, so they're deliberately not previewed
// (they still apply correctly server-side, just first visible on the
// confirmation step/email once the real booking record exists).
const addonsTotal = computed(() =>
  form.addon_ids.reduce((sum, id) => {
    const addon = addons.value.find((a) => a.id === id);
    return sum + (addon ? Number(addon.price) : 0);
  }, 0)
);

const totalAmount = computed(() => {
  const hasBasePrice = props.item.price !== null && props.item.price !== undefined;
  if (!hasBasePrice && addonsTotal.value === 0) {
    return null;
  }
  const subtotal = Math.round(((hasBasePrice ? Number(props.item.price) * quantity.value : 0) + addonsTotal.value) * 100) / 100;
  const taxRate = Number((window.byzoWidget && window.byzoWidget.taxRate) || 0);
  const tax = taxRate > 0 ? Math.round((subtotal * taxRate) / 100 * 100) / 100 : 0;

  return Math.round((subtotal + tax) * 100) / 100;
});

// "Total" only once quantity or tax makes it a sum of something; for a
// single unpriced-by-quantity booking it's just the price.
const totalLabel = computed(() => {
  const taxRate = Number((window.byzoWidget && window.byzoWidget.taxRate) || 0);

  return quantity.value > 1 || taxRate > 0 ? 'Total' : 'Price';
});

const totalValue = computed(() => formatMoney(totalAmount.value, { emptyLabel: 'Free' }));

const requiredCustomFieldsFilled = computed(() =>
  customFields.value.every((field) => {
    if (!field.required) return true;
    const value = form.custom_fields[field.id];
    return field.field_type === 'checkbox' ? value === true : String(value ?? '').trim() !== '';
  })
);

const canSubmit = computed(
  () =>
    form.name.trim() !== '' &&
    /\S+@\S+\.\S+/.test(form.email) &&
    form.party_size >= 1 &&
    form.party_size <= partySizeMax.value &&
    (!form.create_account || form.password.length >= 8) &&
    requiredCustomFieldsFilled.value
);
</script>
