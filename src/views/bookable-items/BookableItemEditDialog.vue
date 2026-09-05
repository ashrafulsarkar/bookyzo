<template>
  <Dialog :open="open" @update:open="(v) => !v && close()">
    <!-- Outside-click never closes: there's real edited data on the table
         from the first step on, and a stray click on the overlay shouldn't
         throw it away. Esc and the X button still close (deliberate
         gestures) — same rule BookableItemAddWizard.vue applies past its
         type picker. -->
    <DialogScrollContent class="max-w-xl" @pointer-down-outside="(event) => event.preventDefault()">
      <DialogHeader>
        <DialogTitle>Edit bookable item</DialogTitle>
        <DialogDescription>Same steps as adding one. Details, availability, and pricing are saved together on the last step.</DialogDescription>
      </DialogHeader>

      <div v-if="!loading && !loadError" class="flex items-center gap-1.5">
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

      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <div v-else-if="loadError" class="flex flex-col gap-4">
        <p class="text-sm text-destructive">{{ loadError }}</p>
        <div class="flex justify-end">
          <Button variant="outline" @click="close">Close</Button>
        </div>
      </div>

      <!-- Mirrors BookableItemAddWizard.vue step for step, reusing the same
           step components with `:initial` seeded from the loaded item
           instead of defaults — so editing feels like the Add flow the
           owner already went through, not a different screen. Minus the
           Type step (immutable after creation), plus Exceptions for
           non-event items. `class="border-0 shadow-none"` falls through
           each step's root Card, same as the Add wizard. -->
      <template v-else>
        <BookingDetailsStep
          v-if="step === 'details'"
          class="border-0 shadow-none"
          context="edit"
          back-label="Cancel"
          :type="wizardData.type"
          :initial="wizardData"
          @next="onDetailsNext"
          @back="close"
        />

        <AvailabilityStep
          v-else-if="step === 'availability'"
          class="border-0 shadow-none"
          :type="wizardData.type"
          :initial="wizardData"
          @next="onAvailabilityNext"
          @back="step = 'details'"
        />

        <!-- Dates (event) / Exceptions (everything else) are list
             management, not form fields: each add/remove hits the server
             right away, so they're outside the accumulate-then-save flow
             the other steps share — said so in the note, so the final Save
             isn't mistaken for what persists them. -->
        <div v-else-if="step === 'dates'" class="flex flex-col gap-4">
          <BookableItemDatesTab :bookable-item-id="itemId" embedded />
          <p class="px-6 text-xs text-muted-foreground">Dates save as you add or remove them — they're not part of the final Save.</p>
          <div class="flex justify-between px-6 pb-6">
            <Button variant="outline" @click="step = 'details'">Back</Button>
            <Button @click="step = 'pricing'">Continue</Button>
          </div>
        </div>

        <div v-else-if="step === 'exceptions'" class="flex flex-col gap-4">
          <BookableItemExceptionsTab :bookable-item-id="itemId" embedded />
          <p class="px-6 text-xs text-muted-foreground">Exceptions save as you add or remove them — they're not part of the final Save.</p>
          <div class="flex justify-between px-6 pb-6">
            <Button variant="outline" @click="step = 'availability'">Back</Button>
            <Button @click="step = 'pricing'">Continue</Button>
          </div>
        </div>

        <PricingStep
          v-else-if="step === 'pricing'"
          class="border-0 shadow-none"
          :type="wizardData.type"
          :currency="currency"
          :initial="wizardData"
          @next="onPricingNext"
          @back="step = wizardData.type === 'event' ? 'dates' : 'exceptions'"
        />

        <BookableItemReviewStep
          v-else-if="step === 'review'"
          class="border-0 shadow-none"
          :data="{ ...wizardData, currency }"
          :saving="saving"
          :error="saveError"
          description="Everything look right? Save your changes."
          submit-label="Save changes"
          saving-label="Saving…"
          @create="save"
          @back="step = 'pricing'"
        />
      </template>
    </DialogScrollContent>
  </Dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Dialog, DialogScrollContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import BookingDetailsStep from '../setup/BookingDetailsStep.vue';
import AvailabilityStep from '../setup/AvailabilityStep.vue';
import PricingStep from '../setup/PricingStep.vue';
import BookableItemDatesTab from './BookableItemDatesTab.vue';
import BookableItemExceptionsTab from './BookableItemExceptionsTab.vue';
import BookableItemReviewStep from './BookableItemReviewStep.vue';
import { api } from '@/api/client';

// Which item is open is owned by the ROUTE (bookable-items.edit →
// BookableItemsList.vue passes route.params.id in as `itemId`), not by
// this component, so deep links and SetupWizard.vue's "Manage" button
// keep working exactly as they did for the old full-page edit screen.
const props = defineProps({
  open: { type: Boolean, default: false },
  itemId: { type: [String, Number], default: null },
});
const emit = defineEmits(['update:open', 'saved']);

const loading = ref(false);
const loadError = ref('');
const saving = ref(false);
const saveError = ref('');
const step = ref('details');
const currency = ref('USD');

// `schedules` stays null for event items (no weekly schedule) and becomes
// the item's real rows for everything else — AvailabilityStep.vue shows
// an array, even an empty one ("closed every day"), exactly as it is.
const wizardData = reactive({
  type: '',
  name: '',
  description: '',
  tags: '',
  duration_minutes: 30,
  capacity: 1,
  price: null,
  status: 'active',
  photo_id: null,
  location: '',
  meeting_link: '',
  schedules: null,
});

const stepOrder = computed(() =>
  wizardData.type === 'event'
    ? ['details', 'dates', 'pricing', 'review']
    : ['details', 'availability', 'exceptions', 'pricing', 'review']
);
const stepLabels = computed(() =>
  wizardData.type === 'event'
    ? ['Details', 'Dates', 'Pricing', 'Review']
    : ['Details', 'Availability', 'Exceptions', 'Pricing', 'Review']
);
const currentIndex = computed(() => stepOrder.value.indexOf(step.value));

// Guards against a slow response for item A landing after the owner has
// already closed and opened item B.
let loadToken = 0;

watch(
  () => [props.open, props.itemId],
  ([isOpen, id]) => {
    if (isOpen && id !== null && id !== undefined && id !== '') {
      load(id);
    }
  },
  { immediate: true }
);

async function load(id) {
  const token = ++loadToken;
  loading.value = true;
  loadError.value = '';
  saveError.value = '';
  step.value = 'details';
  try {
    // Currency only labels the Pricing step; a failed settings fetch
    // shouldn't block editing, so it falls back rather than failing.
    const [item, settings] = await Promise.all([
      api.get(`/bookable-items/${id}`),
      api.get('/settings').catch(() => ({})),
    ]);
    if (token !== loadToken) return;

    currency.value = settings.currency || 'USD';
    Object.assign(wizardData, {
      type: item.type,
      name: item.name,
      description: item.description || '',
      tags: item.tags || '',
      duration_minutes: item.duration_minutes,
      capacity: item.capacity || 1,
      price: item.price === null || item.price === undefined ? null : item.price,
      status: item.status,
      photo_id: item.photo_id || null,
      location: item.location || '',
      meeting_link: item.meeting_link || '',
      schedules: null,
    });

    if (item.type !== 'event') {
      const availability = await api.get(`/bookable-items/${id}/availability`);
      if (token !== loadToken) return;
      wizardData.schedules = availability.schedules.map((s) => ({
        weekday: s.weekday,
        start_time: s.start_time.slice(0, 5),
        end_time: s.end_time.slice(0, 5),
        shift_group: s.shift_group,
      }));
    }
  } catch (e) {
    if (token !== loadToken) return;
    loadError.value = e.message || 'Could not load this bookable item.';
  } finally {
    if (token === loadToken) {
      loading.value = false;
    }
  }
}

function onDetailsNext(details) {
  Object.assign(wizardData, details);
  step.value = wizardData.type === 'event' ? 'dates' : 'availability';
}

function onAvailabilityNext(availability) {
  Object.assign(wizardData, availability);
  step.value = 'exceptions';
}

function onPricingNext(pricing) {
  Object.assign(wizardData, pricing);
  step.value = 'review';
}

async function save() {
  saveError.value = '';
  saving.value = true;
  let succeeded = false;
  try {
    const payload = {
      name: wizardData.name,
      description: wizardData.description,
      tags: wizardData.tags,
      status: wizardData.status,
      price: wizardData.price === '' || wizardData.price === null ? null : wizardData.price,
      // Appointment is single-person by definition (DETAILED-SPEC.md §A2/A3
      // field matrix) regardless of what the hidden field holds; every other
      // type sends its real capacity. Same rule as the Add wizard.
      capacity: wizardData.type === 'appointment' ? 1 : Math.max(1, Number(wizardData.capacity) || 1),
      photo_id: wizardData.photo_id || null,
      location: wizardData.location || null,
      meeting_link: wizardData.meeting_link || null,
    };
    // Event's duration comes from each occurrence (Dates step); the field
    // is hidden for it — don't send a value the owner never saw.
    if (wizardData.type !== 'event') {
      payload.duration_minutes = wizardData.duration_minutes;
    }

    const updated = await api.put(`/bookable-items/${props.itemId}`, payload);
    if (wizardData.type !== 'event') {
      await api.put(`/bookable-items/${props.itemId}/availability`, { schedules: wizardData.schedules });
    }

    toast.success(`"${updated.name}" saved.`);
    emit('saved', updated);
    succeeded = true;
  } catch (e) {
    saveError.value = e.message || 'Could not save this bookable item.';
  } finally {
    saving.value = false;
  }
  // close() refuses to run while `saving` is true, so it comes after the
  // finally above has cleared it.
  if (succeeded) {
    close();
  }
}

function close() {
  // Ignore X/Esc while the save is in flight — the Dialog is controlled
  // by `open`, so returning here keeps it up and the owner sees the
  // outcome (or the error) instead of nothing.
  if (saving.value) {
    return;
  }
  emit('update:open', false);
}
</script>
