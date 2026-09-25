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
            :title="label"
            :aria-current="index === currentIndex ? 'step' : undefined"
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
        {{ __('Skip setup', 'bookyzo') }}
      </button>
    </div>

    <!-- Each step is v-if-mounted, so its local form state dies on Back.
         `:initial` hands the accumulated wizardData back in and every step
         seeds itself from it — Back shows what the owner already
         chose/typed, not the defaults. (BusinessProfileStep saves to
         /settings and re-reads it on mount, so it persists on its own.) -->
    <WelcomeStep v-if="state.step === 'welcome'" @next="advanceTo('booking_type')" />

    <BookingTypeStep
      v-else-if="state.step === 'booking_type'"
      :initial="wizardData.type"
      @next="(type) => { wizardData.type = type; advanceTo('business_profile'); }"
    />

    <BusinessProfileStep
      v-else-if="state.step === 'business_profile'"
      @next="(profile) => { Object.assign(wizardData, profile); advanceTo('booking_details'); }"
      @back="advanceTo('booking_type')"
    />

    <BookingDetailsStep
      v-else-if="state.step === 'booking_details'"
      compact
      :type="wizardData.type"
      :initial="wizardData"
      @next="(details) => { Object.assign(wizardData, details); advanceTo('availability'); }"
      @back="advanceTo('business_profile')"
    />

    <AvailabilityStep
      v-else-if="state.step === 'availability'"
      compact
      :type="wizardData.type"
      :default-start-time="wizardData.default_start_time"
      :default-end-time="wizardData.default_end_time"
      :initial="wizardData"
      @next="(availability) => { Object.assign(wizardData, availability); advanceTo('customer_fields'); }"
      @back="advanceTo('booking_details')"
    />

    <CustomerFieldsStep
      v-else-if="state.step === 'customer_fields'"
      :initial="wizardData"
      @next="(fields) => { Object.assign(wizardData, fields); advanceTo('pricing'); }"
      @back="advanceTo('availability')"
    />

    <PricingStep
      v-else-if="state.step === 'pricing'"
      :type="wizardData.type"
      :currency="wizardData.currency"
      :initial="wizardData"
      @next="(pricing) => { Object.assign(wizardData, pricing); advanceTo('confirmation'); }"
      @back="advanceTo('customer_fields')"
    />

    <ConfirmationStep
      v-else-if="state.step === 'confirmation'"
      :initial="wizardData"
      @next="(confirmation) => { Object.assign(wizardData, confirmation); advanceTo('review'); }"
      @back="advanceTo('pricing')"
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
import { i18n } from '@/lib/i18n';
import { ref, reactive, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../../api/client';
import { markSetupComplete } from '../../router';
import WelcomeStep from './WelcomeStep.vue';
import BookingTypeStep from './BookingTypeStep.vue';
import BusinessProfileStep from './BusinessProfileStep.vue';
import BookingDetailsStep from '../bookable-items/steps/DetailsStep.vue';
import AvailabilityStep from '../bookable-items/steps/AvailabilityStep.vue';
import CustomerFieldsStep from './CustomerFieldsStep.vue';
import PricingStep from '../bookable-items/steps/PricingStep.vue';
import ConfirmationStep from './ConfirmationStep.vue';
import ReviewStep from './ReviewStep.vue';
import SuccessStep from './SuccessStep.vue';

const router = useRouter();

// Deepened 2026-09-03 (DETAILED-SPEC.md §A1) from the old 3-step version
// (type -> business profile -> first bookable item). Matches
// BYZO_Setup::STEPS exactly, minus 'done' which SuccessStep renders
// instead of counting as a numbered progress dot.
const stepLabels = [i18n.__('Welcome', 'bookyzo'), i18n.__('Type', 'bookyzo'), i18n.__('Business', 'bookyzo'), i18n.__('Details', 'bookyzo'), i18n.__('Availability', 'bookyzo'), i18n.__('Customer info', 'bookyzo'), i18n.__('Pricing', 'bookyzo'), i18n.__('Confirmation', 'bookyzo'), i18n.__('Review', 'bookyzo')];
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
//
// `schedules: null` (not []) until the Availability step has been visited
// — AvailabilityStep.vue reads null as "seed every day from the default
// hours" and an array (even empty = closed every day) as "the owner's
// last answer, show it as left".
const wizardData = reactive({
  type: '',
  name: '',
  description: '',
  duration_minutes: 30,
  capacity: 1,
  schedules: null,
  occurrences: null,
  require_phone: false,
  price: null,
  location: '',
  meeting_link: '',
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

// Set once POST /bookable-items succeeds. Create is several calls (settings,
// the item, then its availability or first occurrence); if a later one
// fails, a retry must UPDATE the existing item rather than POST a second
// one — which, on Free's 1-active-per-type cap (BYZO_Capabilities), would
// otherwise fail with the per-type limit error and strand a half-configured
// item behind it. Same guard as BookableItemWizard.vue's submit().
const createdId = ref(null);
const createdType = ref('');

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
      // The weekly hours picked here become the site's business hours, so
      // Settings has one place showing opening hours rather than a pair of
      // default times beside a separate grid (QA report §4.7 / §5.3).
      ...(wizardData.schedules && wizardData.schedules.length ? { business_hours: wizardData.schedules } : {}),
    });

    const payload = {
      type: wizardData.type,
      name: wizardData.name,
      description: wizardData.description,
      status: 'active',
      price: wizardData.price === '' || wizardData.price === null ? null : wizardData.price,
      // Same appointment-is-always-1 rule as BookableItemWizard.vue's own submit().
      capacity: wizardData.type === 'appointment' ? 1 : Math.max(1, Number(wizardData.capacity) || 1),
      location: wizardData.location || null,
      meeting_link: wizardData.meeting_link || null,
    };
    if ('event' !== wizardData.type) {
      payload.duration_minutes = wizardData.duration_minutes;
    }

    // Type is immutable server-side (update_item's byzo_type_immutable),
    // so if the owner went Back after a partial failure and switched type,
    // the half-created item can't be reused — drop it and start clean.
    if (createdId.value && createdType.value !== wizardData.type) {
      await api.delete(`/bookable-items/${createdId.value}`);
      createdId.value = null;
      createdType.value = '';
    }

    let created;
    if (createdId.value) {
      created = await api.put(`/bookable-items/${createdId.value}`, payload);
    } else {
      created = await api.post('/bookable-items', payload);
      createdId.value = created.id;
      createdType.value = wizardData.type;
    }

    if ('event' === wizardData.type) {
      for (const occurrence of wizardData.occurrences || []) {
        await api.post(`/bookable-items/${created.id}/slots`, { date: occurrence.date, start_time: occurrence.start_time, end_time: occurrence.end_time });
      }
    } else {
      await api.put(`/bookable-items/${created.id}/availability`, { schedules: wizardData.schedules });
    }

    createdItemId.value = created.id;
    createdId.value = null;
    createdType.value = '';
    state.value = await api.put('/setup', { step: 'done' });
    // Tell the route guard setup is done now, even though SuccessStep is
    // shown in place rather than redirecting immediately — its own
    // "View Dashboard" button is what actually navigates away.
    markSetupComplete();
  } catch (e) {
    createError.value = e.message || i18n.__('Could not finish setup. Please check your answers and try again.', 'bookyzo');
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
  // (the wizard's TypeStep.vue) rather than re-entering this completed
  // wizard — byzo_setup_step is already 'done' by this point, and the
  // setup wizard's own state machine isn't built to restart partway
  // through. Deliberately NOT filtered to "types not yet used" yet
  // (DETAILED-SPEC.md's aspiration) — that needs the wizard's TypeStep.vue
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
