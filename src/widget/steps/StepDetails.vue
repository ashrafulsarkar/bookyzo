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
        <span class="summary-label">{{ __('When', 'bookyzo') }}</span>
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
      <div v-if="quote && quote.discount_amount" class="summary-row summary-row-discount">
        <span class="summary-label">{{ quote.package ? __('Package credit', 'bookyzo') : __('Discount', 'bookyzo') }} ({{ quote.discount_code }})</span>
        <span class="summary-value">−{{ formatMoney(quote.discount_amount) }}</span>
      </div>
      <div v-if="quote && quote.booking_fee_amount" class="summary-row">
        <span class="summary-label">{{ __('Booking fee', 'bookyzo') }}</span>
        <span class="summary-value">{{ formatMoney(quote.booking_fee_amount) }}</span>
      </div>
      <div v-if="quote && quote.tax_amount" class="summary-row">
        <span class="summary-label">{{ __('Tax', 'bookyzo') }}</span>
        <span class="summary-value">{{ formatMoney(quote.tax_amount) }}</span>
      </div>
      <div class="summary-row summary-row-total">
        <span class="summary-label">{{ totalLabel }}</span>
        <span class="summary-value">{{ totalValue }}</span>
      </div>
    </div>

    <div v-if="showPartySize" class="field">
      <label for="bw-party-size">{{ partySizeLabel }}</label>
      <input id="bw-party-size" v-model.number="form.party_size" type="number" min="1" :max="partySizeMax" />
    </div>

    <!-- Per-ticket attendee details (Phase 2 step 20, DETAILED-SPEC.md §3
         B4) — an Event booking with more than one ticket collects each
         attendee's own name (required) and email (optional), instead of
         one contact name for the whole purchase. Gated on a Pro
         capability AND event type AND quantity > 1 — absent otherwise,
         same "absent, not disabled" rule every capability toggle here
         follows. The main Name/Email fields below still collect the
         PURCHASER's own contact info regardless — this is additive. -->
    <div v-if="showAttendeeDetails" class="field">
      <label>{{ __('Attendee details', 'bookyzo') }}</label>
      <div v-for="(attendee, index) in form.attendees" :key="index" class="attendee-row">
        <span class="attendee-index">#{{ index + 1 }}</span>
        <input v-model="attendee.name" type="text" :placeholder="sprintf(__('Attendee %s name', 'bookyzo'), index + 1)" />
        <input v-model="attendee.email" type="email" :placeholder="__('Email (optional)', 'bookyzo')" />
      </div>
    </div>

    <!-- Ticket tiers (Phase 2 step 12 continued, DETAILED-SPEC.md §3 B7
         Event) — Pro-defined, fetched by bookable item; entirely absent
         when Pro is off or no tiers are configured for this event (a
         plain flat-price booking then, unchanged). Radio, not checkbox —
         exactly one tier per booking, unlike add-ons below. -->
    <div v-if="ticketTiers.length" class="field">
      <label>{{ __('Ticket type', 'bookyzo') }}</label>
      <label v-for="tier in ticketTiers" :key="tier.id" class="checkbox-label addon-row">
        <span>
          <input v-model.number="form.ticket_tier_id" type="radio" :value="tier.id" />
          {{ tier.name }}
        </span>
        <span>{{ formatMoney(tier.price) }}</span>
      </label>
    </div>

    <!-- Add-ons (Phase 2 step 17, DETAILED-SPEC.md §3 B6) — Pro-defined,
         fetched by bookable item; entirely absent when Pro is off or
         nothing's configured (addons is then just []). The server is the
         sole authority on the actual charge either way — this list is
         only a UX preview so the total shown doesn't undersell it. -->
    <div v-if="addons.length" class="field">
      <label>{{ __('Add-ons', 'bookyzo') }} <span class="optional">{{ __('optional', 'bookyzo') }}</span></label>
      <label v-for="addon in addons" :key="addon.id" class="checkbox-label addon-row">
        <span>
          <input v-model="form.addon_ids" type="checkbox" :value="addon.id" />
          {{ addon.name }}
        </span>
        <span>{{ formatMoney(addon.price) }}</span>
      </label>
    </div>

    <div class="field">
      <label for="bw-name">{{ __('Name', 'bookyzo') }}</label>
      <input id="bw-name" v-model="form.name" type="text" autocomplete="name" :placeholder="__('Your full name', 'bookyzo')" />
    </div>

    <div class="field">
      <label for="bw-email">{{ __('Email', 'bookyzo') }}</label>
      <input id="bw-email" v-model="form.email" type="email" autocomplete="email" placeholder="you@example.com" />
    </div>

    <div class="field">
      <label for="bw-phone">{{ __('Phone', 'bookyzo') }} <span class="optional">{{ __('optional', 'bookyzo') }}</span></label>
      <input id="bw-phone" v-model="form.phone" type="tel" autocomplete="tel" />
    </div>

    <div class="field">
      <label for="bw-notes">{{ __('Notes', 'bookyzo') }} <span class="optional">{{ __('optional', 'bookyzo') }}</span></label>
      <textarea id="bw-notes" v-model="form.notes" rows="2" :placeholder="__('Anything we should know?', 'bookyzo')" />
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
        {{ __('Create an account to manage this booking later', 'bookyzo') }}
      </label>
      <input
        v-if="form.create_account"
        v-model="form.password"
        type="password"
        :placeholder="__('Choose a password', 'bookyzo')"
        autocomplete="new-password"
        minlength="8"
      />
    </div>

    <!-- Marketing consent (QA report 2026-09-22 §4.6) — unticked by
         default and absent entirely on a site that sends no marketing
         email. Booking confirmations and reminders are unaffected either
         way; this is only about offers and news. -->
    <div v-if="marketingEnabled" class="field">
      <label class="checkbox-label">
        <input v-model="form.marketing_opt_in" type="checkbox" />
        {{ __('Email me about news and offers', 'bookyzo') }}
      </label>
    </div>

    <!-- Custom form fields (Phase 2 step 16, DETAILED-SPEC.md §3 B11) —
         Pro-defined, fetched by bookable item; entirely absent when Pro is
         off or nothing's configured (customFields is then just []). -->
    <div v-for="field in customFields" :key="field.id" class="field">
      <label v-if="field.field_type === 'checkbox'" class="checkbox-label">
        <input v-model="form.custom_fields[field.id]" type="checkbox" />
        {{ field.label }} <span v-if="!field.required" class="optional">{{ __('optional', 'bookyzo') }}</span>
      </label>
      <template v-else>
        <label :for="`bw-cf-${field.id}`">
          {{ field.label }} <span v-if="!field.required" class="optional">{{ __('optional', 'bookyzo') }}</span>
        </label>
        <select v-if="field.field_type === 'dropdown'" :id="`bw-cf-${field.id}`" v-model="form.custom_fields[field.id]">
          <option value="" disabled>{{ __('Select…', 'bookyzo') }}</option>
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

    <!-- Discount or package code (QA report §6.1 A2) — only when the site
         has codes (window.byzoWidget.codesEnabled, an add-on's). The
         server says what it's worth; nothing is taken on trust here. -->
    <div v-if="codesEnabled" class="field">
      <label for="bw-code">{{ __('Discount or package code', 'bookyzo') }} <span class="optional">{{ __('(optional)', 'bookyzo') }}</span></label>
      <div v-if="!form.discount_code" class="code-row">
        <input id="bw-code" v-model="codeInput" type="text" autocomplete="off" @keydown.enter.prevent="applyCode" />
        <button type="button" class="btn btn-outline" :disabled="!codeInput.trim() || checkingCode" @click="applyCode">{{ checkingCode ? __('Checking…', 'bookyzo') : __('Apply', 'bookyzo') }}</button>
      </div>
      <div v-else class="code-applied">
        <span>{{ sprintf(__('%s applied', 'bookyzo'), form.discount_code) }}</span>
        <button type="button" class="link-button" @click="removeCode">{{ __('Remove', 'bookyzo') }}</button>
      </div>
      <p v-if="codeError" class="field-error">{{ codeError }}</p>
    </div>

    <!-- Honeypot (BYZO_Public_Guard) — visually hidden, never seen or
         filled by a real visitor, tabindex/autocomplete off so
         keyboard/autofill users can't stumble into it either. Bots that
         blanket-fill every input on a form trip this. -->
    <div class="honeypot" aria-hidden="true">
      <label for="bw-website">{{ __('Website', 'bookyzo') }}</label>
      <input id="bw-website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="actions">
      <button type="button" class="btn btn-outline" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</button>
      <button type="button" class="btn btn-primary" :disabled="!canSubmit || submitting" @click="$emit('submit', form)">
        {{ submitting ? copy.submitting : copy.submit }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { i18n, locale } from '@/lib/i18n';
import { reactive, computed, ref, onMounted, watch } from 'vue';
import { copyFor } from '../copy';
import { formatMoney } from '@/lib/money';
import { getCustomFields, getAddons, getTicketTiers, getQuote } from '../api';

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
  return i18n.sprintf(i18n.__('%1$s at %2$s', 'bookyzo'), date.toLocaleDateString(locale, { weekday: 'short', month: 'short', day: 'numeric' }), date.toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' }));
});

// Hidden when the type has no quantity concept (appointment, whose
// quantityLabel is null) or when an earlier step already asked (Table).
const showPartySize = computed(() => copy.value.quantityLabel !== null && props.partySize === null);
const partySizeLabel = computed(() => copy.value.quantityLabel || i18n.__('Party size', 'bookyzo'));
const partySizeMax = computed(() => Math.max(1, props.slot.capacity_remaining || 1));

// Phase 1.7 step 6 — window.byzoWidget.customerAccountsEnabled (added
// alongside restUrl/stripeEnabled/etc. by BYZO_Public_Widget::enqueue_assets()).
// A logged-in visitor already has an account — no opt-in for them — and
// their details come prefilled (BYZO_Public_Widget's currentCustomer).
const currentCustomer = (window.byzoWidget && window.byzoWidget.currentCustomer) || null;
const accountsEnabled = Boolean(window.byzoWidget && window.byzoWidget.customerAccountsEnabled) && !currentCustomer;
const marketingEnabled = Boolean(window.byzoWidget && window.byzoWidget.marketingEnabled);

const form = reactive({
  party_size: props.partySize || 1,
  name: currentCustomer?.name || '',
  email: currentCustomer?.email || '',
  phone: currentCustomer?.phone || '',
  notes: '',
  website: '',
  create_account: false,
  password: '',
  marketing_opt_in: false,
  discount_code: '',
  custom_fields: {},
  addon_ids: [],
  attendees: [],
  ticket_tier_id: null,
});

// Phase 2 step 16 (DETAILED-SPEC.md §3 B11) — Pro-defined custom form
// fields for this bookable item. Empty when Pro is off/unconfigured, in
// which case this whole section of the form is simply absent.
const customFields = ref([]);

// Phase 2 step 17 (DETAILED-SPEC.md §3 B6) — Pro-defined add-ons for this
// bookable item. Same "empty means absent" shape as customFields above.
const addons = ref([]);

// Phase 2 step 12 continued (DETAILED-SPEC.md §3 B7 Event) — Pro-defined
// ticket tiers for this bookable item. Same "empty means absent" shape.
const ticketTiers = ref([]);

// The bookable item whose extras apply — the chosen slot's own. Same as
// item.id for a normal booking; for "any available staff" (item.id null)
// it's the staff member the chosen time belongs to.
const extrasItemId = computed(() => props.slot.bookable_item_id || props.item.id);

onMounted(async () => {
  try {
    const fields = await getCustomFields(extrasItemId.value);
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
    addons.value = await getAddons(extrasItemId.value);
  } catch {
    addons.value = [];
  }

  if ('event' === props.item.type) {
    try {
      ticketTiers.value = await getTicketTiers(extrasItemId.value);
    } catch {
      ticketTiers.value = [];
    }
  }
});

// Quantity the price is multiplied by — the Table step's party size when an
// earlier step asked, otherwise this step's own field.
const quantity = computed(() => Math.max(1, Number(props.partySize ?? form.party_size) || 1));

// Phase 2 step 20 (DETAILED-SPEC.md §3 B4) — window.byzoWidget.collectAttendeeDetailsEnabled,
// the site's "Ask for each ticket holder's name" setting (BYZO_Public_Widget::enqueue_assets()).
const collectAttendeeDetailsEnabled = Boolean(window.byzoWidget && window.byzoWidget.collectAttendeeDetailsEnabled);
const showAttendeeDetails = computed(() => collectAttendeeDetailsEnabled && 'event' === props.item.type && quantity.value > 1);

// Keeps form.attendees sized to the current quantity — growing/shrinking
// party size adds/removes attendee rows rather than leaving stale extra
// entries or forcing a fixed count chosen too early.
watch(
  quantity,
  (newQuantity) => {
    if (!showAttendeeDetails.value) {
      form.attendees = [];
      return;
    }
    const next = form.attendees.slice(0, newQuantity);
    while (next.length < newQuantity) {
      next.push({ name: '', email: '' });
    }
    form.attendees = next;
  },
  { immediate: true }
);

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

// Selected tier's price overrides the bookable item's own flat price,
// mirroring attempt_create_on_slot()'s own precedence server-side — a
// display-only preview, same "server stays the sole authority" caveat
// addonsTotal's own comment already makes.
const selectedTierPrice = computed(() => {
  const tier = ticketTiers.value.find((t) => t.id === form.ticket_tier_id);
  return tier ? Number(tier.price) : null;
});

// The server's own quote (POST /bookings/quote → BYZO_Booking::price_breakdown(),
// the same function create() charges with) — QA report 2026-09-22 B3: a JS
// re-implementation of the arithmetic here showed $70 for a booking Pro's
// weekend pricing rule then charged $80.50. Re-fetched whenever anything
// that affects the price changes; the local estimate below is only a
// fallback for the moment before the first quote lands, or if it fails.
const quote = ref(null);
let quoteRequest = 0;
let quoteTimer = null;

async function refreshQuote() {
  const requestId = ++quoteRequest;
  try {
    const anyStaff = props.item.service_id && !props.item.staff_id;
    const result = await getQuote({
      // "Any available staff" is priced like create_for_service() books it:
      // by service and start time, not by one staff member's slot.
      ...(anyStaff ? { start_datetime: props.slot.site_start_datetime || props.slot.start_datetime } : { slot_id: props.slot.id }),
      service_id: props.item.service_id || undefined,
      party_size: quantity.value,
      ticket_tier_id: form.ticket_tier_id,
      addon_ids: form.addon_ids,
      discount_code: form.discount_code || undefined,
      customer_email: form.email || undefined,
    });
    // A slower, older response must not overwrite a newer one.
    if (requestId !== quoteRequest) return;
    // A code that no longer fits (party size changed, say) comes off, with why.
    if (form.discount_code && result.discount_error) {
      codeError.value = result.discount_error;
      form.discount_code = '';
      return;
    }
    quote.value = result;
  } catch {
    if (requestId === quoteRequest) quote.value = null;
  }
}

// --- Discount / package code ----------------------------------------------
const codesEnabled = Boolean(window.byzoWidget && window.byzoWidget.codesEnabled);
const codeInput = ref('');
const codeError = ref('');
const checkingCode = ref(false);

async function applyCode() {
  const code = codeInput.value.trim().toUpperCase();
  if (!code) return;
  checkingCode.value = true;
  codeError.value = '';
  form.discount_code = code;
  await refreshQuote();
  checkingCode.value = false;
}

function removeCode() {
  form.discount_code = '';
  codeInput.value = '';
  codeError.value = '';
}

watch(
  () => [quantity.value, form.ticket_tier_id, form.addon_ids.join(','), form.discount_code],
  () => {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(refreshQuote, 200);
  },
  { immediate: true }
);

const estimatedAmount = computed(() => {
  const unitPrice = selectedTierPrice.value !== null ? selectedTierPrice.value : props.item.price;
  const hasBasePrice = unitPrice !== null && unitPrice !== undefined;
  if (!hasBasePrice && addonsTotal.value === 0) {
    return null;
  }
  const subtotal = Math.round(((hasBasePrice ? Number(unitPrice) * quantity.value : 0) + addonsTotal.value) * 100) / 100;
  const taxRate = Number((window.byzoWidget && window.byzoWidget.taxRate) || 0);
  const tax = taxRate > 0 ? Math.round((subtotal * taxRate) / 100 * 100) / 100 : 0;

  return Math.round((subtotal + tax) * 100) / 100;
});

const totalAmount = computed(() => (quote.value ? quote.value.payment_amount : estimatedAmount.value));

// "Total" only once quantity or tax makes it a sum of something; for a
// single unpriced-by-quantity booking it's just the price.
const totalLabel = computed(() => {
  const taxRate = Number((window.byzoWidget && window.byzoWidget.taxRate) || 0);
  const hasExtras = quote.value && (quote.value.booking_fee_amount || quote.value.tax_amount || quote.value.addons_total || quote.value.discount_amount);

  return quantity.value > 1 || taxRate > 0 || hasExtras ? i18n.__('Total', 'bookyzo') : i18n.__('Price', 'bookyzo');
});

const totalValue = computed(() => formatMoney(totalAmount.value, { emptyLabel: i18n.__('Free', 'bookyzo') }));

const requiredCustomFieldsFilled = computed(() =>
  customFields.value.every((field) => {
    if (!field.required) return true;
    const value = form.custom_fields[field.id];
    return field.field_type === 'checkbox' ? value === true : String(value ?? '').trim() !== '';
  })
);

// Each attendee's name is required (email stays optional) whenever the
// section is shown at all — an event ticket with no name defeats the
// whole point of collecting them per-ticket.
const attendeeDetailsFilled = computed(
  () => !showAttendeeDetails.value || form.attendees.every((attendee) => attendee.name.trim() !== '')
);

// A ticket-tiered event REQUIRES picking one — there's no sensible
// "default" tier to fall back to silently once tiers exist at all.
const ticketTierFilled = computed(() => ticketTiers.value.length === 0 || form.ticket_tier_id !== null);

const canSubmit = computed(
  () =>
    form.name.trim() !== '' &&
    /\S+@\S+\.\S+/.test(form.email) &&
    form.party_size >= 1 &&
    form.party_size <= partySizeMax.value &&
    (!form.create_account || form.password.length >= 8) &&
    requiredCustomFieldsFilled.value &&
    attendeeDetailsFilled.value &&
    ticketTierFilled.value
);
</script>
