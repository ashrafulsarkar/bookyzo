<template>
  <Dialog :open="open" @update:open="(v) => !v && close()">
    <DialogScrollContent class="max-w-xl" @pointer-down-outside="onPointerDownOutside">
      <DialogHeader>
        <DialogTitle>Add bookable item</DialogTitle>
        <DialogDescription>Set it up in a few quick steps — you can fine-tune everything later.</DialogDescription>
      </DialogHeader>

      <div class="flex items-center gap-1.5">
        <template v-for="(label, index) in stepLabels" :key="label">
          <div
            :title="label"
            :aria-current="index === currentIndex ? 'step' : undefined"
            :class="[
              'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-medium',
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
            class="h-px flex-1"
            :class="index < currentIndex ? 'bg-primary' : 'bg-border'"
          />
        </template>
      </div>

      <!-- Reuses the setup wizard's own step components (BookingDetailsStep,
           AvailabilityStep, PricingStep) so a bookable item created here
           ends up in exactly the shape SetupWizard.vue's createEverything()
           produces — one accumulate-then-create flow, not two divergent
           ones. `class="border-0 shadow-none"` falls through each step's
           root Card (Card.vue reads $attrs.class — the same fallthrough
           BookableItemEditDialog.vue relies on for its steps) so the
           step doesn't render as a bordered card nested inside the dialog's
           own bordered surface.

           Each step is v-if-mounted, so its local form state dies on Back.
           `:initial` hands the accumulated wizardData back in, and every
           step seeds itself from it — Back shows what the owner already
           chose/typed, not the defaults. -->
      <BookableItemTypeStep
        v-if="step === 'type'"
        class="border-0 shadow-none"
        :initial="wizardData.type"
        @selected="onTypeSelected"
      />
      <BookingDetailsStep
        v-else-if="step === 'details'"
        class="border-0 shadow-none"
        context="add"
        :type="wizardData.type"
        :initial="wizardData"
        @next="onDetailsNext"
        @back="step = 'type'"
      />
      <AvailabilityStep
        v-else-if="step === 'availability'"
        class="border-0 shadow-none"
        :type="wizardData.type"
        :default-start-time="defaultStartTime"
        :default-end-time="defaultEndTime"
        :initial="wizardData"
        @next="onAvailabilityNext"
        @back="step = 'details'"
      />
      <PricingStep
        v-else-if="step === 'pricing'"
        class="border-0 shadow-none"
        :type="wizardData.type"
        :currency="currency"
        :initial="wizardData"
        @next="onPricingNext"
        @back="step = 'availability'"
      />
      <BookableItemReviewStep
        v-else-if="step === 'review'"
        class="border-0 shadow-none"
        :data="{ ...wizardData, currency }"
        :saving="creating"
        :error="createError"
        @create="createItem"
        @back="step = 'pricing'"
      />
    </DialogScrollContent>
  </Dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Dialog, DialogScrollContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import BookableItemTypeStep from './BookableItemTypeStep.vue';
import BookingDetailsStep from '../setup/BookingDetailsStep.vue';
import AvailabilityStep from '../setup/AvailabilityStep.vue';
import PricingStep from '../setup/PricingStep.vue';
import BookableItemReviewStep from './BookableItemReviewStep.vue';
import { api } from '@/api/client';

const props = defineProps({
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'created']);

const STEP_ORDER = ['type', 'details', 'availability', 'pricing', 'review'];
const stepLabels = ['Type', 'Details', 'Availability', 'Pricing', 'Review'];

const step = ref('type');
const currentIndex = computed(() => STEP_ORDER.indexOf(step.value));

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
  occurrence: { date: '', start_time: '19:00', end_time: '22:00' },
  price: null,
  location: '',
  meeting_link: '',
});

// Seeded from /settings each time the dialog opens (same fields
// BusinessProfileStep.vue collects during onboarding) so the Availability
// step's default weekly hours and the Pricing step's currency label match
// what the owner already configured, instead of hardcoding USD/09:00-17:00.
const currency = ref('USD');
const defaultStartTime = ref('09:00');
const defaultEndTime = ref('17:00');

const creating = ref(false);
const createError = ref('');

// Set once POST /bookable-items succeeds. Create is two calls (the item,
// then its availability or first occurrence); if the second one fails, a
// retry must UPDATE this item rather than POST a second one — which, on
// Free's 1-active-per-type cap (BYZO_Capabilities), would otherwise fail
// with the per-type limit error and strand a half-configured item behind it.
const createdId = ref(null);
const createdType = ref('');

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      resetWizard();
      loadDefaults();
    }
  }
);

function resetWizard() {
  step.value = 'type';
  wizardData.type = '';
  wizardData.name = '';
  wizardData.description = '';
  wizardData.duration_minutes = 30;
  wizardData.capacity = 1;
  wizardData.schedules = null;
  wizardData.occurrence = { date: '', start_time: '19:00', end_time: '22:00' };
  wizardData.price = null;
  wizardData.location = '';
  wizardData.meeting_link = '';
  createError.value = '';
  createdId.value = null;
  createdType.value = '';
}

async function loadDefaults() {
  try {
    const settings = await api.get('/settings');
    currency.value = settings.currency || 'USD';
    defaultStartTime.value = settings.default_start_time || '09:00';
    defaultEndTime.value = settings.default_end_time || '17:00';
  } catch (e) {
    // Keep the sane fallback defaults above — a failed settings fetch
    // shouldn't block adding a bookable item.
  }
}

function onTypeSelected(type) {
  wizardData.type = type;
  step.value = 'details';
}

function onDetailsNext(details) {
  Object.assign(wizardData, details);
  step.value = 'availability';
}

function onAvailabilityNext(availability) {
  Object.assign(wizardData, availability);
  step.value = 'pricing';
}

function onPricingNext(pricing) {
  Object.assign(wizardData, pricing);
  step.value = 'review';
}

async function createItem() {
  createError.value = '';
  creating.value = true;
  let succeeded = false;
  try {
    const payload = {
      type: wizardData.type,
      name: wizardData.name,
      description: wizardData.description,
      status: 'active',
      price: wizardData.price === '' || wizardData.price === null ? null : wizardData.price,
      // Same appointment-is-always-1 rule as BookableItemEditDialog.vue's save().
      capacity: wizardData.type === 'appointment' ? 1 : Math.max(1, Number(wizardData.capacity) || 1),
      location: wizardData.location || null,
      meeting_link: wizardData.meeting_link || null,
    };
    if (wizardData.type !== 'event') {
      payload.duration_minutes = wizardData.duration_minutes;
    }

    // Type is immutable server-side (update_item's byzo_type_immutable),
    // so if the owner went Back after a partial failure and switched type,
    // the half-created item from the earlier attempt can't be reused —
    // drop it and start clean rather than leave an orphan.
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

    if (wizardData.type === 'event') {
      await api.post(`/bookable-items/${created.id}/slots`, { ...wizardData.occurrence });
    } else {
      await api.put(`/bookable-items/${created.id}/availability`, { schedules: wizardData.schedules });
    }

    createdId.value = null;
    createdType.value = '';
    toast.success(`"${created.name}" created.`);
    emit('created', created);
    succeeded = true;
  } catch (e) {
    createError.value = e.message || 'Could not create this bookable item. Please check your answers and try again.';
  } finally {
    creating.value = false;
  }
  // close() refuses to run while `creating` is true (see below), so it has
  // to come after the finally above has cleared it.
  if (succeeded) {
    close();
  }
}

// Once the owner is past the type picker there's real typed data on the
// table — a stray click on the overlay shouldn't throw it away. Esc and
// the X button still close (those are deliberate gestures).
function onPointerDownOutside(event) {
  if (currentIndex.value > 0 || creating.value) {
    event.preventDefault();
  }
}

function close() {
  // Ignore X/Esc/outside-click while the create calls are in flight —
  // the Dialog is controlled by `open`, so returning here keeps it up
  // and the owner sees the outcome (or the error) instead of nothing.
  if (creating.value) {
    return;
  }

  // Closing after a partial failure: the item itself exists (without
  // its availability/first date). Say so and refresh the list, so it
  // shows up where the owner can finish it from the edit screen instead
  // of silently vanishing until the next reload.
  if (createdId.value) {
    const missing = wizardData.type === 'event' ? 'first date' : 'availability';
    toast.warning(`"${wizardData.name}" was created, but its ${missing} was not saved — open it from the list to finish setting it up.`);
    emit('created', { id: createdId.value, name: wizardData.name, type: createdType.value });
  }

  emit('update:open', false);
}
</script>
