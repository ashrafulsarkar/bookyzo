<template>
  <div>
    <h2>Your details</h2>

    <div v-if="showPartySize" class="field">
      <label for="bw-party-size">{{ partySizeLabel }}</label>
      <input id="bw-party-size" v-model.number="form.party_size" type="number" min="1" :max="partySizeMax" />
    </div>

    <div class="field">
      <label for="bw-name">Name</label>
      <input id="bw-name" v-model="form.name" type="text" autocomplete="name" />
    </div>

    <div class="field">
      <label for="bw-email">Email</label>
      <input id="bw-email" v-model="form.email" type="email" autocomplete="email" />
    </div>

    <div class="field">
      <label for="bw-phone">Phone (optional)</label>
      <input id="bw-phone" v-model="form.phone" type="tel" autocomplete="tel" />
    </div>

    <div class="field">
      <label for="bw-notes">Notes (optional)</label>
      <textarea id="bw-notes" v-model="form.notes" rows="2" />
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
        {{ submitting ? 'Booking…' : 'Confirm booking' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { reactive, computed } from 'vue';

const props = defineProps({
  item: { type: Object, required: true },
  slot: { type: Object, required: true },
  submitting: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['submit', 'back']);

// Party size / quantity input for capacity>1 bookable items
// (DETAILED-SPEC.md §A5) — hidden for Appointment (always 1), max = the
// chosen slot's remaining capacity. The server re-validates this
// regardless (BYZO_Booking::create()'s atomic capacity_remaining check),
// this is only a UX guide against picking a number that's already known
// to fail.
const PARTY_SIZE_LABELS = {
  table: 'Party size',
  event: 'Tickets',
  slot: 'Spots',
};
const showPartySize = computed(() => props.item.type !== 'appointment');
const partySizeLabel = computed(() => PARTY_SIZE_LABELS[props.item.type] || 'Party size');
const partySizeMax = computed(() => Math.max(1, props.slot.capacity_remaining || 1));

// Phase 1.7 step 6 — window.byzoWidget.customerAccountsEnabled (added
// alongside restUrl/stripeEnabled/etc. by BYZO_Public_Widget::enqueue_assets()).
const accountsEnabled = Boolean(window.byzoWidget && window.byzoWidget.customerAccountsEnabled);

const form = reactive({
  party_size: 1,
  name: '',
  email: '',
  phone: '',
  notes: '',
  website: '',
  create_account: false,
  password: '',
});

const canSubmit = computed(
  () =>
    form.name.trim() !== '' &&
    /\S+@\S+\.\S+/.test(form.email) &&
    form.party_size >= 1 &&
    form.party_size <= partySizeMax.value &&
    (!form.create_account || form.password.length >= 8)
);
</script>
