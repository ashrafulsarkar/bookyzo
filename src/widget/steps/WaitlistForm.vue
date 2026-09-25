<template>
  <div class="waitlist">
    <template v-if="!joined">
      <p class="waitlist-title">{{ sprintf(__('%s is full', 'bookyzo'), whenLabel) }}</p>
      <p class="waitlist-sub">{{ __('Join the waitlist and we\'ll email you if a place opens up. It\'s first come, first served then, so book quickly when you hear from us.', 'bookyzo') }}</p>

      <div class="field">
        <label for="bw-wl-name">{{ __('Name', 'bookyzo') }}</label>
        <input id="bw-wl-name" v-model="form.customer_name" type="text" autocomplete="name" />
      </div>
      <div class="field">
        <label for="bw-wl-email">{{ __('Email', 'bookyzo') }}</label>
        <input id="bw-wl-email" v-model="form.customer_email" type="email" autocomplete="email" />
      </div>
      <div class="field">
        <label for="bw-wl-phone">{{ __('Phone', 'bookyzo') }} <span class="optional">{{ __('(optional)', 'bookyzo') }}</span></label>
        <input id="bw-wl-phone" v-model="form.customer_phone" type="tel" autocomplete="tel" />
      </div>
      <div v-if="maxParty > 1" class="field">
        <label for="bw-wl-party">{{ partyLabel }}</label>
        <select id="bw-wl-party" v-model.number="form.party_size">
          <option v-for="n in maxParty" :key="n" :value="n">{{ n }}</option>
        </select>
      </div>

      <!-- Honeypot, same as the booking form (BYZO_Public_Guard). -->
      <div class="honeypot" aria-hidden="true">
        <label for="bw-wl-website">{{ __('Website', 'bookyzo') }}</label>
        <input id="bw-wl-website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <button type="button" class="btn btn-primary" :disabled="sending || !canSend" @click="send">
        {{ sending ? __('Joining…', 'bookyzo') : __('Join the waitlist', 'bookyzo') }}
      </button>
    </template>

    <div v-else class="waitlist-done" role="status">
      <p class="waitlist-title">{{ __('You\'re on the waitlist', 'bookyzo') }}</p>
      <p class="waitlist-sub">{{ sprintf(__('If a place opens up for %1$s, we\'ll email %2$s straight away.', 'bookyzo'), whenLabel, form.customer_email) }}</p>
    </div>
  </div>
</template>

<script setup>
import { i18n, locale } from '@/lib/i18n';
// The waitlist form shown in place of Continue when the visitor picks a
// full time (QA report 2026-09-22 §6.2.6). Only rendered when the site
// keeps a waitlist (window.byzoWidget.waitlistEnabled, an add-on's).
import { ref, computed, watch } from 'vue';
import { joinWaitlist } from '../api';

const props = defineProps({
  slot: { type: Object, required: true },
  item: { type: Object, required: true },
  partySize: { type: Number, default: 1 },
});

const known = (window.byzoWidget && window.byzoWidget.currentCustomer) || {};
const form = ref({ customer_name: known.name || '', customer_email: known.email || '', customer_phone: known.phone || '', party_size: props.partySize || 1, website: '' });
const sending = ref(false);
const joined = ref(false);
const error = ref('');

// Appointments are one person; everything else up to the item's capacity.
const maxParty = computed(() => (props.item.type === 'appointment' ? 1 : Math.max(1, props.item.capacity || 1)));
const partyLabel = computed(() => (props.item.type === 'table' ? i18n.__('Party size', 'bookyzo') : props.item.type === 'event' ? i18n.__('Tickets', 'bookyzo') : i18n.__('Places', 'bookyzo')));
const canSend = computed(() => form.value.customer_name.trim() && /\S+@\S+\.\S+/.test(form.value.customer_email));

const whenLabel = computed(() =>
  new Date(props.slot.start_datetime.replace(' ', 'T')).toLocaleString(locale, { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' })
);

// A different full time picked: start over.
watch(
  () => props.slot.id,
  () => {
    joined.value = false;
    error.value = '';
  }
);

async function send() {
  sending.value = true;
  error.value = '';
  try {
    await joinWaitlist({ ...form.value, slot_id: props.slot.id, page_url: window.location.href.split('#')[0] });
    joined.value = true;
  } catch (e) {
    error.value = e.message;
  } finally {
    sending.value = false;
  }
}
</script>
