<template>
  <Dialog :open="open" @update:open="(v) => !v && close()">
    <DialogScrollContent class="max-w-xl" @pointer-down-outside="onPointerDownOutside" @escape-key-down="onEscapeKeyDown">
      <DialogHeader>
        <DialogTitle>{{ isEdit ? 'Edit bookable item' : 'Add bookable item' }}</DialogTitle>
        <DialogDescription>
          {{ isEdit ? 'The same steps as adding one — nothing is saved until you confirm on the last step.' : 'Set it up in a few quick steps — you can fine-tune everything later.' }}
        </DialogDescription>
      </DialogHeader>

      <ByzoStepProgress :labels="stepLabels" :current="currentIndex" />

      <ByzoLoading v-if="loading" />

      <div v-else-if="loadError" class="flex flex-col gap-4">
        <p class="text-sm text-destructive">{{ loadError }}</p>
        <div class="flex justify-end">
          <Button variant="outline" @click="close">Close</Button>
        </div>
      </div>

      <!-- Each step is v-if-mounted, so its local form state dies on Back.
           `:initial` hands the accumulated wizardData back in (on edit,
           pre-filled from the loaded item) and every step seeds itself
           from it. `STEP_CARD_CLASS` falls through each step's root Card
           (Card.vue reads $attrs.class) so the step doesn't render as a
           bordered, separately-padded card inside the dialog's own
           bordered, padded surface. -->
      <template v-else>
        <TypeStep
          v-if="step === 'type'"
          :class="STEP_CARD_CLASS"
          :initial="wizardData.type"
          :locked="isEdit"
          @selected="onTypeSelected"
        />
        <DetailsStep
          v-else-if="step === 'details'"
          :class="STEP_CARD_CLASS"
          :type="wizardData.type"
          :initial="wizardData"
          :hide-back="isEdit"
          @next="onDetailsNext"
          @back="step = 'type'"
        />
        <AvailabilityStep
          v-else-if="step === 'availability'"
          :class="STEP_CARD_CLASS"
          :type="wizardData.type"
          :default-start-time="defaultStartTime"
          :default-end-time="defaultEndTime"
          :initial="wizardData"
          @next="onAvailabilityNext"
          @back="step = 'details'"
        />
        <PricingStep
          v-else-if="step === 'pricing'"
          :class="STEP_CARD_CLASS"
          :type="wizardData.type"
          :currency="currency"
          :initial="wizardData"
          @next="onPricingNext"
          @back="step = 'availability'"
        />
        <ReviewStep
          v-else-if="step === 'review'"
          :class="STEP_CARD_CLASS"
          :data="{ ...wizardData, currency }"
          :saving="saving"
          :error="saveError"
          :submit-label="isEdit ? 'Save changes' : 'Create bookable item'"
          :saving-label="isEdit ? 'Saving…' : 'Creating…'"
          @create="submit"
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
import ByzoStepProgress from '@/components/base/ByzoStepProgress.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import TypeStep from './steps/TypeStep.vue';
import DetailsStep from './steps/DetailsStep.vue';
import AvailabilityStep from './steps/AvailabilityStep.vue';
import PricingStep from './steps/PricingStep.vue';
import ReviewStep from './steps/ReviewStep.vue';
import { api } from '@/api/client';

// ONE popup for both Add and Edit — an edit is the exact same step-by-step
// flow with everything pre-filled from the loaded item (and the Type step
// locked, since BYZO_REST_Bookable_Items_Controller::update_item() rejects
// a type change). Which mode this is in is owned by the ROUTE:
// BookableItemsList.vue passes route.params.id in as `itemId` for
// bookable-items.edit and nothing for bookable-items.new.
const props = defineProps({
  open: { type: Boolean, default: false },
  itemId: { type: [String, Number], default: null },
});
const emit = defineEmits(['update:open', 'saved']);

const isEdit = computed(() => props.itemId !== null && props.itemId !== undefined && props.itemId !== '');

// A step's Card IS the dialog body here, so its card chrome and its own
// p-6 stacked on top of DialogContent's border and p-6: the step's title,
// fields and buttons all sat 24px further in than the dialog's own title
// and the progress bar directly above them. Dropping the border/shadow and
// the sections' horizontal padding puts every edge on one line. The `!` is
// needed because `[&>*]:px-0` and CardHeader/CardContent/CardFooter's own
// `p-6` are the same specificity, so without it source order decides.
// SetupWizard.vue renders the same steps unmodified — there they ARE
// standalone cards on a page and keep their padding.
const STEP_CARD_CLASS = 'border-0 shadow-none [&>*]:!px-0 [&>*:first-child]:!pt-0 [&>*:last-child]:!pb-0';

// The Type step is skipped entirely when editing: the type can't be
// changed after creation (update_item() rejects it), so it rendered as a
// step whose only control was already-selected-and-disabled and whose
// only possible action was clicking Continue past it. The type still
// shows on Details and Review, where it's information rather than a
// dead choice.
const STEP_ORDER = computed(() =>
  isEdit.value
    ? ['details', 'availability', 'pricing', 'review']
    : ['type', 'details', 'availability', 'pricing', 'review']
);
const step = ref('type');
const currentIndex = computed(() => STEP_ORDER.value.indexOf(step.value));
const stepLabels = computed(() => {
  const availabilityLabel = wizardData.type === 'event' ? 'Dates' : 'Availability';
  const labels = ['Details', availabilityLabel, 'Pricing', 'Review'];

  return isEdit.value ? labels : ['Type', ...labels];
});

// Accumulated across the steps and sent to the server ONCE, from Review.
// `schedules` / `exceptions` / `occurrences` are null (not []) until the
// Availability step has been visited — AvailabilityStep.vue reads null as
// "seed from defaults" and an array (even empty) as "the owner's answer".
const wizardData = reactive(emptyData());

function emptyData() {
  return {
    type: '',
    name: '',
    description: '',
    photo_id: null,
    status: 'active',
    duration_minutes: 30,
    capacity: 1,
    location: '',
    meeting_link: '',
    uses_business_hours: false,
    schedules: null,
    exceptions: null,
    occurrences: null,
    price: null,
  };
}

// Seeded from /settings each time the dialog opens (same fields
// BusinessProfileStep.vue collects during onboarding) so the Availability
// step's default weekly hours and the Pricing step's currency label match
// what the owner already configured, instead of hardcoding USD/09:00-17:00.
const currency = ref('USD');
const defaultStartTime = ref('09:00');
const defaultEndTime = ref('17:00');

const loading = ref(false);
const loadError = ref('');
const saving = ref(false);
const saveError = ref('');

// Edit: the ids of the exception / occurrence rows that existed when the
// item was loaded. Save diffs the current lists against these (missing →
// DELETE, id: null → POST) and prunes each id once its DELETE has
// landed, so a retry after a mid-way failure doesn't re-delete a gone row.
const original = reactive({ exceptionIds: [], occurrenceIds: [] });

// Create: set once POST /bookable-items succeeds. Create is several calls
// (the item, then its availability/exceptions or its dates); if a later
// one fails, a retry must UPDATE this item rather than POST a second one —
// which, on Free's 1-active-per-type cap (BYZO_Capabilities), would
// otherwise fail with the per-type limit error and strand a half-configured
// item behind it.
const createdId = ref(null);
const createdType = ref('');

// Guards against a slow response for item A landing after the owner has
// already closed this and opened item B.
let loadToken = 0;

watch(
  () => [props.open, props.itemId],
  ([isOpen]) => {
    if (!isOpen) {
      return;
    }
    resetWizard();
    loadDefaults();
    if (isEdit.value) {
      loadItem(props.itemId);
    }
  },
  { immediate: true }
);

function resetWizard() {
  step.value = isEdit.value ? 'details' : 'type';
  Object.assign(wizardData, emptyData());
  original.exceptionIds = [];
  original.occurrenceIds = [];
  loadError.value = '';
  saveError.value = '';
  createdId.value = null;
  createdType.value = '';
}

async function loadDefaults() {
  try {
    const settings = await api.get('/settings');
    currency.value = settings.currency || 'USD';
    // Business hours are the site's opening hours (Settings), so a new
    // item starts from them; the older default_start_time/default_end_time
    // pair is the fallback for a site that never set any (QA report §4.7).
    const hours = Array.isArray(settings.business_hours) ? settings.business_hours : [];
    defaultStartTime.value = hours[0]?.start_time || settings.default_start_time || '09:00';
    defaultEndTime.value = hours[0]?.end_time || settings.default_end_time || '17:00';
  } catch (e) {
    // Keep the sane fallback defaults above — a failed settings fetch
    // shouldn't block adding a bookable item.
  }
}

// 'YYYY-MM-DD HH:MM:SS' slot rows → the { date, start_time, end_time }
// shape AvailabilityStep.vue's occurrence list edits.
function toOccurrence(slot) {
  const [date, start = ''] = String(slot.start_datetime).split(' ');
  const end = String(slot.end_datetime).split(' ')[1] || '';
  return {
    id: slot.id,
    date,
    start_time: start.slice(0, 5),
    end_time: end.slice(0, 5),
    status: slot.status,
    capacity_remaining: slot.capacity_remaining,
  };
}

async function loadItem(id) {
  const token = ++loadToken;
  loading.value = true;
  try {
    const item = await api.get(`/bookable-items/${id}`);

    let schedules = null;
    let exceptions = null;
    let occurrences = null;
    if (item.type === 'event') {
      const slots = await api.get(`/bookable-items/${id}/slots`);
      occurrences = slots.map(toOccurrence);
    } else {
      const availability = await api.get(`/bookable-items/${id}/availability`);
      schedules = availability.schedules.map((s) => ({
        weekday: s.weekday,
        start_time: s.start_time.slice(0, 5),
        end_time: s.end_time.slice(0, 5),
        shift_group: s.shift_group,
      }));
      exceptions = availability.exceptions.map((x) => ({
        id: x.id,
        date: x.date,
        is_closed: Boolean(x.is_closed),
        start_time: x.start_time ? x.start_time.slice(0, 5) : null,
        end_time: x.end_time ? x.end_time.slice(0, 5) : null,
      }));
    }

    if (token !== loadToken) return;

    Object.assign(wizardData, {
      type: item.type,
      name: item.name,
      description: item.description || '',
      photo_id: item.photo_id || null,
      status: item.status || 'active',
      duration_minutes: item.duration_minutes,
      capacity: item.capacity || 1,
      location: item.location || '',
      meeting_link: item.meeting_link || '',
      uses_business_hours: Boolean(item.uses_business_hours),
      price: item.price === null || item.price === undefined ? null : String(item.price),
      schedules,
      exceptions,
      occurrences,
    });
    original.exceptionIds = (exceptions || []).map((x) => x.id);
    original.occurrenceIds = (occurrences || []).map((o) => o.id);
  } catch (e) {
    if (token !== loadToken) return;
    loadError.value = e.message || 'Could not load this bookable item.';
  } finally {
    if (token === loadToken) {
      loading.value = false;
    }
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

function buildPayload() {
  const payload = {
    type: wizardData.type,
    name: wizardData.name,
    description: wizardData.description,
    status: wizardData.status || 'active',
    price: wizardData.price === '' || wizardData.price === null ? null : wizardData.price,
    // Appointment is single-person by definition (DETAILED-SPEC.md §A2/A3
    // field matrix) regardless of what the hidden field holds; every other
    // type sends its real capacity.
    capacity: wizardData.type === 'appointment' ? 1 : Math.max(1, Number(wizardData.capacity) || 1),
    photo_id: wizardData.photo_id || null,
    location: wizardData.location || null,
    meeting_link: wizardData.meeting_link || null,
    // Meaningless for event items (no weekly schedule at all) — always
    // false there since AvailabilityStep.vue never shows this toggle for
    // that type, sent unconditionally anyway since it's harmless either way.
    uses_business_hours: Boolean(wizardData.uses_business_hours),
  };
  // Event's duration comes from each occurrence (Dates step); the field
  // is hidden for it — don't send a value the owner never saw.
  if (wizardData.type !== 'event') {
    payload.duration_minutes = wizardData.duration_minutes;
  }
  return payload;
}

// Availability / exceptions (or dates, for an event) for item `id`.
// Deletes run before adds. Every success is recorded on the spot (id
// pruned from `original`, or written onto the new row) so a retry after a
// mid-way failure picks up exactly where this left off, never re-posting
// a row that already landed.
async function syncSchedule(id) {
  if (wizardData.type === 'event') {
    for (const slotId of [...original.occurrenceIds]) {
      if (!(wizardData.occurrences || []).some((o) => o.id === slotId)) {
        await api.delete(`/bookable-items/${id}/slots/${slotId}`);
        original.occurrenceIds = original.occurrenceIds.filter((x) => x !== slotId);
      }
    }
    for (const occurrence of wizardData.occurrences || []) {
      if (occurrence.id) continue;
      const created = await api.post(`/bookable-items/${id}/slots`, {
        date: occurrence.date,
        start_time: occurrence.start_time,
        end_time: occurrence.end_time,
      });
      occurrence.id = created.id;
    }
    return;
  }

  // Linked items keep whatever custom schedule they last had dormant on
  // the server (BYZO_Slot_Generator ignores it while linked) rather than
  // having it silently overwritten by whatever the hidden grid still
  // holds locally — see DETAILED-SPEC.md §A2. Exceptions still sync
  // either way: a linked staff member can still take an individual day off.
  if (!wizardData.uses_business_hours) {
    await api.put(`/bookable-items/${id}/availability`, { schedules: wizardData.schedules || [] });
  }

  for (const exceptionId of [...original.exceptionIds]) {
    if (!(wizardData.exceptions || []).some((x) => x.id === exceptionId)) {
      await api.delete(`/bookable-items/${id}/availability/exceptions/${exceptionId}`);
      original.exceptionIds = original.exceptionIds.filter((x) => x !== exceptionId);
    }
  }
  for (const exception of wizardData.exceptions || []) {
    if (exception.id) continue;
    const payload = { date: exception.date, is_closed: exception.is_closed };
    if (!exception.is_closed) {
      payload.start_time = exception.start_time;
      payload.end_time = exception.end_time;
    }
    // This endpoint returns the whole list rather than the new row — pick
    // ours back out by date (one exception per date).
    const result = await api.post(`/bookable-items/${id}/availability/exceptions`, payload);
    const saved = (result.exceptions || []).find((x) => x.date === exception.date);
    exception.id = saved ? saved.id : true;
  }
}

async function submit() {
  saveError.value = '';
  saving.value = true;
  let succeeded = false;
  try {
    const payload = buildPayload();

    if (isEdit.value) {
      const updated = await api.put(`/bookable-items/${props.itemId}`, payload);
      await syncSchedule(props.itemId);
      toast.success(`"${updated.name}" saved.`);
      emit('saved', updated);
    } else {
      // Type is immutable server-side, so if the owner went Back after a
      // partial failure and switched type, the half-created item from the
      // earlier attempt can't be reused — drop it and start clean rather
      // than leave an orphan.
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

      await syncSchedule(created.id);

      createdId.value = null;
      createdType.value = '';
      toast.success(`"${created.name}" created.`);
      emit('saved', created);
    }
    succeeded = true;
  } catch (e) {
    saveError.value =
      e.message || (isEdit.value ? 'Could not save this bookable item. Please check your answers and try again.' : 'Could not create this bookable item. Please check your answers and try again.');
  } finally {
    saving.value = false;
  }
  // close() refuses to run while `saving` is true (see below), so it has
  // to come after the finally above has cleared it.
  if (succeeded) {
    close();
  }
}

// Once the owner is past the type picker (or is editing at all) there's
// real data on the table — a stray click on the overlay shouldn't throw
// it away. Esc and the X button still close (those are deliberate).
function onPointerDownOutside(event) {
  if (isEdit.value || currentIndex.value > 0 || saving.value) {
    event.preventDefault();
  }
}

// wp.media (the Details step's photo picker) adds `modal-open` to <body>
// while its own modal is up; Esc there should close IT, not this dialog.
function onEscapeKeyDown(event) {
  if (saving.value || document.body.classList.contains('modal-open')) {
    event.preventDefault();
  }
}

function close() {
  // Ignore X/Esc/outside-click while the save calls are in flight — the
  // Dialog is controlled by `open`, so returning here keeps it up and the
  // owner sees the outcome (or the error) instead of nothing.
  if (saving.value) {
    return;
  }

  // Closing after a partial failure on create: the item itself exists
  // (without its availability/dates). Say so and refresh the list, so it
  // shows up where the owner can finish it via Edit instead of silently
  // vanishing until the next reload.
  if (createdId.value) {
    const missing = wizardData.type === 'event' ? 'dates were' : 'availability was';
    toast.warning(`"${wizardData.name}" was created, but its ${missing} not saved — open it from the list to finish setting it up.`);
    emit('saved', { id: createdId.value, name: wizardData.name, type: createdType.value });
  }

  emit('update:open', false);
}
</script>
