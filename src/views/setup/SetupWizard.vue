<template>
  <!-- `w-full`: without it the column shrank to each step's own content,
       so the card (and the step dots above it) jumped between 380px and
       530px wide from one step to the next. The `[&_h3]` rule gives every
       step's CardTitle a real heading size; at the app's default 14px it
       read like a label. -->
  <div class="byzo-setup mx-auto w-full max-w-2xl px-4 py-12 sm:px-6 [&_h3]:text-lg">
    <!-- The step dots stretch to the card's own width (flex-1 connectors)
         instead of a fixed 32px each — nine fixed-width steps plus the
         Skip link ran ~140px past the card on the right. -->
    <div class="mb-3 flex min-h-5 items-center justify-between gap-4 text-sm">
      <span v-if="currentIndex >= 0 && currentIndex < stepLabels.length" class="text-muted-foreground">
        {{ sprintf(/*! translators: 1: current step number, 2: total steps, 3: step name. */ __('Step %1$s of %2$s · %3$s', 'bookyzo'), currentIndex + 1, stepLabels.length, stepLabels[currentIndex]) }}
      </span>
    </div>
    <ol class="mb-8 flex w-full items-center">
      <li
        v-for="(label, index) in stepLabels"
        :key="label"
        class="flex items-center"
        :class="index < stepLabels.length - 1 ? 'flex-1' : ''"
      >
        <div
          :title="label"
          :aria-current="index === currentIndex ? 'step' : undefined"
          :class="[
            'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-medium sm:h-7 sm:w-7',
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
          class="mx-1 h-px flex-1 sm:mx-2"
          :class="index < currentIndex ? 'bg-primary' : 'bg-border'"
        />
      </li>
    </ol>

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
      @back="advanceTo('welcome')"
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
      @next="(confirmation) => { Object.assign(wizardData, confirmation); advanceTo(showProStep ? 'pro' : 'review'); }"
      @back="advanceTo('pricing')"
    />

    <ProStep v-else-if="state.step === 'pro'" @next="advanceTo('review')" @back="advanceTo('confirmation')" />

    <ReviewStep
      v-else-if="state.step === 'review'"
      :data="wizardData"
      :saving="creating"
      :error="createError"
      @create="createEverything"
      @back="advanceTo(showProStep ? 'pro' : 'confirmation')"
    />

    <SuccessStep
      v-else-if="state.step === 'done'"
      :bookable-item-id="createdItemId"
      @go-dashboard="goDashboard"
      @go-manage="goManage"
      @add-another="goAddAnother"
    />

    <!-- Below the card, not beside the step counter: up there it sat in the
         eye line of every step and read like the main way forward. -->
    <div v-if="!['review', 'done'].includes(state.step)" class="mt-6 text-center">
      <button
        type="button"
        class="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
        @click="skip"
      >
        {{ __('Skip setup — I\'ll do this later', 'bookyzo') }}
      </button>
    </div>
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
import ProStep from './ProStep.vue';
import ReviewStep from './ReviewStep.vue';
import SuccessStep from './SuccessStep.vue';

const router = useRouter();

// Deepened 2026-09-03 (DETAILED-SPEC.md §A1) from the old 3-step version
// (type -> business profile -> first bookable item). Matches
// BYZO_Setup::STEPS exactly, minus 'done' which SuccessStep renders
// instead of counting as a numbered progress dot.
// The Pro page only exists while Pro isn't active — the same
// byzo_show_get_pro() that decides the Get Pro screen. It never reads
// Pro's license (Free doesn't).
const showProStep = !!(window.byzoAdmin && window.byzoAdmin.showGetPro);
const STEP_LABELS = {
  welcome: i18n.__('Welcome', 'bookyzo'),
  booking_type: i18n.__('Type', 'bookyzo'),
  business_profile: i18n.__('Business', 'bookyzo'),
  booking_details: i18n.__('Details', 'bookyzo'),
  availability: i18n.__('Availability', 'bookyzo'),
  customer_fields: i18n.__('Customer info', 'bookyzo'),
  pricing: i18n.__('Pricing', 'bookyzo'),
  confirmation: i18n.__('Confirmation', 'bookyzo'),
  pro: i18n.__('Pro', 'bookyzo'),
  review: i18n.__('Review', 'bookyzo'),
};
const STEP_ORDER = Object.keys(STEP_LABELS).filter((step) => showProStep || 'pro' !== step).concat('done');
const stepLabels = STEP_ORDER.filter((step) => 'done' !== step).map((step) => STEP_LABELS[step]);
const state = ref({ step: 'welcome', skipped: false, needed: true });

// Accumulated across every step client-side and only sent to the server
// ONCE, from the Review step's Create action (DETAILED-SPEC.md §A1:
// "Create action — unchanged endpoint sequence... no new REST calls"
// from Review itself) — unlike the old wizard, where each step saved
// immediately. byzo_setup_step persists the STEP server-side; the answers
// themselves survive a reload through the browser draft below.
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
// one, which would leave a half-configured duplicate behind it. Same guard
// as BookableItemWizard.vue's submit().
const createdId = ref(null);
const createdType = ref('');

const currentIndex = computed(() => STEP_ORDER.indexOf(state.value.step));

// The step is saved server-side (byzo_setup_step) but the answers only
// live in wizardData, so a reload used to resume at Review with an empty
// type and name and "Closed every day" — one click from creating a broken
// item. The answers are now kept in this browser too, and a resume that
// still lacks them is sent back to the first step that asks for them.
// Keyed by admin path so two sites on one host don't share a draft.
const DRAFT_KEY = `byzo_setup_draft:${window.location.pathname}`;

function saveDraft() {
  try {
    window.localStorage.setItem(DRAFT_KEY, JSON.stringify(wizardData));
  } catch (e) {
    // Storage blocked (private window, quota) — same-session Back/Next still works.
  }
}

function loadDraft() {
  try {
    const raw = window.localStorage.getItem(DRAFT_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (e) {
    return null;
  }
}

function clearDraft() {
  try {
    window.localStorage.removeItem(DRAFT_KEY);
  } catch (e) {
    // Nothing to clear.
  }
}

/** The earliest step whose answer the resumed step depends on but is missing. */
function firstMissingStep(step) {
  const at = STEP_ORDER.indexOf(step);
  if (at > STEP_ORDER.indexOf('booking_type') && !wizardData.type) return 'booking_type';
  if (at > STEP_ORDER.indexOf('booking_details') && !String(wizardData.name || '').trim()) return 'booking_details';
  if (at > STEP_ORDER.indexOf('availability') && 'event' !== wizardData.type && null === wizardData.schedules) return 'availability';
  return null;
}

onMounted(async () => {
  try {
    const loaded = await api.get('/setup');
    if (!loaded.needed) {
      clearDraft();
      state.value = loaded;
      markSetupComplete();
      router.replace({ name: 'dashboard' });
      return;
    }
    const draft = loadDraft();
    if (draft && 'object' === typeof draft) {
      Object.keys(wizardData).forEach((key) => {
        if (key in draft) wizardData[key] = draft[key];
      });
    }
    const missing = 'done' === loaded.step ? null : firstMissingStep(loaded.step);
    // Resumed on the Pro page after Pro was activated meanwhile: that
    // page no longer applies, so carry on to Review.
    const resumeAt = missing || ('pro' === loaded.step && !showProStep ? 'review' : null);
    state.value = resumeAt ? await api.put('/setup', { step: resumeAt }) : loaded;
  } catch (error) {
    // Setup state failing to load shouldn't strand the owner on a blank
    // page — fall back to step 1 rather than showing an error screen for
    // what is, worst case, a resumable wizard.
    state.value = { step: 'welcome', skipped: false, needed: true };
  }
});

async function advanceTo(step) {
  saveDraft();
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
      // 1.0.3 — the Availability step's optional buffer; null = business-wide.
      buffer_before_minutes: wizardData.buffer_before_minutes ?? null,
      buffer_after_minutes: wizardData.buffer_after_minutes ?? null,
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
    clearDraft();
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
  // through.
  router.push({ name: 'bookable-items.new' });
}

async function skip() {
  await api.put('/setup', { skip: true });
  clearDraft();
  markSetupComplete();
  router.replace({ name: 'dashboard' });
}
</script>
