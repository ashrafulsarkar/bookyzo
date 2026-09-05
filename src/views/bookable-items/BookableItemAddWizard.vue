<template>
  <Dialog :open="open" @update:open="(v) => !v && close()">
    <DialogScrollContent class="max-w-xl">
      <DialogHeader>
        <DialogTitle>Add bookable item</DialogTitle>
        <DialogDescription>Set it up in a few quick steps — you can fine-tune everything later.</DialogDescription>
      </DialogHeader>

      <div class="flex items-center gap-1.5">
        <template v-for="(label, index) in stepLabels" :key="label">
          <div
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
           root Card (Card.vue reads $attrs.class, same pattern already used
           by `<Card class="max-w-xl">` in BookableItemDetailsTab.vue) so the
           step doesn't render as a bordered card nested inside the dialog's
           own bordered surface. -->
      <BookableItemTypeStep
        v-if="step === 'type'"
        class="border-0 shadow-none"
        @selected="onTypeSelected"
      />
      <BookingDetailsStep
        v-else-if="step === 'details'"
        class="border-0 shadow-none"
        :type="wizardData.type"
        @next="onDetailsNext"
        @back="step = 'type'"
      />
      <AvailabilityStep
        v-else-if="step === 'availability'"
        class="border-0 shadow-none"
        :type="wizardData.type"
        :default-start-time="defaultStartTime"
        :default-end-time="defaultEndTime"
        @next="onAvailabilityNext"
        @back="step = 'details'"
      />
      <PricingStep
        v-else-if="step === 'pricing'"
        class="border-0 shadow-none"
        :type="wizardData.type"
        :currency="currency"
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

const wizardData = reactive({
  type: '',
  name: '',
  description: '',
  duration_minutes: 30,
  capacity: 1,
  schedules: [],
  occurrence: { date: '', start_time: '19:00', end_time: '22:00' },
  price: null,
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
  wizardData.schedules = [];
  wizardData.occurrence = { date: '', start_time: '19:00', end_time: '22:00' };
  wizardData.price = null;
  createError.value = '';
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
  try {
    const payload = {
      type: wizardData.type,
      name: wizardData.name,
      description: wizardData.description,
      status: 'active',
      price: wizardData.price === '' || wizardData.price === null ? null : wizardData.price,
      // Same appointment-is-always-1 rule as BookableItemForm.vue's save().
      capacity: wizardData.type === 'appointment' ? 1 : Math.max(1, Number(wizardData.capacity) || 1),
    };
    if (wizardData.type !== 'event') {
      payload.duration_minutes = wizardData.duration_minutes;
    }

    const created = await api.post('/bookable-items', payload);

    if (wizardData.type === 'event') {
      await api.post(`/bookable-items/${created.id}/slots`, { ...wizardData.occurrence });
    } else {
      await api.put(`/bookable-items/${created.id}/availability`, { schedules: wizardData.schedules });
    }

    toast.success(`"${created.name}" created.`);
    emit('created', created);
    close();
  } catch (e) {
    createError.value = e.message || 'Could not create this bookable item. Please check your answers and try again.';
  } finally {
    creating.value = false;
  }
}

function close() {
  emit('update:open', false);
}
</script>
