<template>
  <div class="byzo-setup mx-auto max-w-xl px-6 py-12">
    <div class="mb-8 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <div
          v-for="(label, index) in stepLabels"
          :key="label"
          class="flex items-center gap-2"
        >
          <div
            :class="[
              'flex h-7 w-7 items-center justify-center rounded-full text-xs font-medium',
              index < currentIndex
                ? 'bg-primary text-primary-foreground'
                : index === currentIndex
                  ? 'border-2 border-primary text-primary'
                  : 'border border-border text-muted-foreground',
            ]"
          >
            {{ index + 1 }}
          </div>
          <div
            v-if="index < stepLabels.length - 1"
            class="h-px w-8"
            :class="index < currentIndex ? 'bg-primary' : 'bg-border'"
          />
        </div>
      </div>
      <button
        v-if="!['review', 'done'].includes(state.step)"
        type="button"
        class="text-sm text-muted-foreground underline-offset-4 hover:underline"
        @click="skip"
      >
        Skip setup
      </button>
    </div>

    <WelcomeStep v-if="state.step === 'welcome'" @next="advanceTo('booking_type')" />

    <BookingTypeStep
      v-else-if="state.step === 'booking_type'"
      @next="(type) => { wizardData.type = type; advanceTo('business_profile'); }"
    />

    <BusinessProfileStep
      v-else-if="state.step === 'business_profile'"
      @next="(profile) => { Object.assign(wizardData, profile); advanceTo('booking_details'); }"
      @back="advanceTo('booking_type')"
    />

    <BookingDetailsStep
      v-else-if="state.step === 'booking_details'"
      :type="wizardData.type"
      @next="(details) => { Object.assign(wizardData, details); advanceTo('availability'); }"
      @back="advanceTo('business_profile')"
    />

    <AvailabilityStep
      v-else-if="state.step === 'availability'"
      :type="wizardData.type"
      :default-start-time="wizardData.default_start_time"
      :default-end-time="wizardData.default_end_time"
      @next="(availability) => { Object.assign(wizardData, availability); advanceTo('customer_fields'); }"
      @back="advanceTo('booking_details')"
    />

    <CustomerFieldsStep
      v-else-if="state.step === 'customer_fields'"
      @next="(fields) => { Object.assign(wizardData, fields); advanceTo('pricing'); }"
      @back="advanceTo('availability')"
    />

    <PricingStep
      v-else-if="state.step === 'pricing'"
      :type="wizardData.type"
      :currency="wizardData.currency"
      @next="(pricing) => { Object.assign(wizardData, pricing); advanceTo('confirmation'); }"
      @back="advanceTo('customer_fields')"
    />

    <ConfirmationStep
      v-else-if="state.step === 'confirmation'"
      @next="(confirmation) => { Object.assign(wizardData, confirmation); advanceTo('review'); }"
      @back="advanceTo('customer_fields')"
    />

    <ReviewStep
      v-else-if="state.step === 'review'"
      :data="wizardData"
      :saving="creating"
      :error="createError"
      @create="createEverything"
      @back="advanceTo('confirmation')"
    />

    <SuccessStep
      v-else-if="state.step === 'done'"
      :bookable-item-id="createdItemId"
      @go-dashboard="goDashboard"
      @go-manage="goManage"
      @add-another="goAddAnother"
    />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../../api/client';
import { markSetupComplete } from '../../router';
import WelcomeStep from './WelcomeStep.vue';
import BookingTypeStep from './BookingTypeStep.vue';
import BusinessProfileStep from './BusinessProfileStep.vue';
import BookingDetailsStep from './BookingDetailsStep.vue';
import AvailabilityStep from './AvailabilityStep.vue';
import CustomerFieldsStep from './CustomerFieldsStep.vue';
import PricingStep from './PricingStep.vue';
import ConfirmationStep from './ConfirmationStep.vue';
import ReviewStep from './ReviewStep.vue';
import SuccessStep from './SuccessStep.vue';

const router = useRouter();

// Deepened 2026-09-03 (DETAILED-SPEC.md §A1) from the old 3-step version
// (type -> business profile -> first bookable item). Matches
// BYZO_Setup::STEPS exactly, minus 'done' which SuccessStep renders
// instead of counting as a numbered progress dot.
const stepLabels = ['Welcome', 'Type', 'Business', 'Details', 'Availability', 'Customer info', 'Pricing', 'Confirmation', 'Review'];
const state = ref({ step: 'welcome', skipped: false, needed: true });

// Accumulated across every step client-side and only sent to the server
// ONCE, from the Review step's Create action (DETAILED-SPEC.md §A1:
// "Create action — unchanged endpoint sequence... no new REST calls"
// from Review itself) — unlike the old wizard, where each step saved
// immediately. Known trade-off: reloading the browser mid-wizard still
// resumes at the right STEP (byzo_setup_step persists server-side), but
// this accumulated data does not survive a real page reload, only a
// same-session step-back/forward. Not built out further since nothing
// in the spec calls for full draft persistence.
const wizardData = reactive({
  type: '',
  name: '',
  description: '',
  duration_minutes: 30,
  capacity: 1,
  schedules: [],
  occurrence: { date: '', start_time: '19:00', end_time: '22:00' },
  require_phone: false,
  price: null,
  currency: 'USD',
  default_start_time: '09:00',
  default_end_time: '17:00',
  auto_confirm: false,
  send_confirmation_email: true,
  send_admin_notification: true,
});

const creating = ref(false);
const createError = ref('');
const createdItemId = ref(null);

const currentIndex = computed(() => {
  const order = ['welcome', 'booking_type', 'business_profile', 'booking_details', 'availability', 'customer_fields', 'pricing', 'confirmation', 'review', 'done'];
  return order.indexOf(state.value.step);
});

onMounted(async () => {
  try {
    state.value = await api.get('/setup');
    if (!state.value.needed) {
      markSetupComplete();
      router.replace({ name: 'dashboard' });
    }
  } catch (error) {
    // Setup state failing to load shouldn't strand the owner on a blank
    // page — fall back to step 1 rather than showing an error screen for
    // what is, worst case, a resumable wizard.
    state.value = { step: 'welcome', skipped: false, needed: true };
  }
});

async function advanceTo(step) {
  state.value = await api.put('/setup', { step });
}

async function createEverything() {
  createError.value = '';
  creating.value = true;
  try {
    await api.put('/settings', {
      require_phone: wizardData.require_phone,
      auto_confirm: wizardData.auto_confirm,
      send_confirmation_email: wizardData.send_confirmation_email,
      send_admin_notification: wizardData.send_admin_notification,
    });

    const payload = {
      type: wizardData.type,
      name: wizardData.name,
      description: wizardData.description,
      status: 'active',
      price: wizardData.price === '' || wizardData.price === null ? null : wizardData.price,
      // Same appointment-is-always-1 rule as BookableItemForm.vue's own save().
      capacity: wizardData.type === 'appointment' ? 1 : Math.max(1, Number(wizardData.capacity) || 1),
    };
    if ('event' !== wizardData.type) {
      payload.duration_minutes = wizardData.duration_minutes;
    }

    const created = await api.post('/bookable-items', payload);

    if ('event' === wizardData.type) {
      await api.post(`/bookable-items/${created.id}/slots`, { ...wizardData.occurrence });
    } else {
      await api.put(`/bookable-items/${created.id}/availability`, { schedules: wizardData.schedules });
    }

    createdItemId.value = created.id;
    state.value = await api.put('/setup', { step: 'done' });
    // Tell the route guard setup is done now, even though SuccessStep is
    // shown in place rather than redirecting immediately — its own
    // "View Dashboard" button is what actually navigates away.
    markSetupComplete();
  } catch (e) {
    createError.value = e.message || 'Could not finish setup. Please check your answers and try again.';
  } finally {
    creating.value = false;
  }
}

function goDashboard() {
  router.push({ name: 'dashboard' });
}

function goManage() {
  router.push({ name: 'bookable-items.edit', params: { id: createdItemId.value } });
}

function goAddAnother() {
  // Re-uses the regular Add New flow's own type picker
  // (BookableItemTypeStep.vue) rather than re-entering this completed
  // wizard — byzo_setup_step is already 'done' by this point, and the
  // setup wizard's own state machine isn't built to restart partway
  // through. Deliberately NOT filtered to "types not yet used" yet
  // (DETAILED-SPEC.md's aspiration) — that needs BookableItemTypeStep.vue
  // to know which types already have an active item, which it doesn't
  // fetch today; flagged as a follow-up, not silently skipped.
  router.push({ name: 'bookable-items.new' });
}

async function skip() {
  await api.put('/setup', { skip: true });
  markSetupComplete();
  router.replace({ name: 'dashboard' });
}
</script>
