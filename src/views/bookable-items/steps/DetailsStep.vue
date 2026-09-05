<template>
  <Card ref="rootEl">
    <CardHeader>
      <CardTitle>{{ compact ? `Tell us about your first ${typeLabel.toLowerCase()}` : `${typeLabel} details` }}</CardTitle>
      <CardDescription>{{ compact ? 'You can add photos, tags, and more later.' : 'Only the name is required — everything else is optional.' }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <div class="flex flex-col gap-1.5">
        <Label for="wizard-item-name">Name</Label>
        <Input id="wizard-item-name" v-model="form.name" type="text" />
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="wizard-item-description">Description (optional)</Label>
        <Textarea id="wizard-item-description" v-model="form.description" rows="2" />
      </div>

      <template v-if="!compact">
        <div class="flex flex-col gap-1.5">
          <Label for="wizard-item-tags">Tags</Label>
          <Input id="wizard-item-tags" v-model="form.tags" type="text" placeholder="e.g. haircut, senior stylist" />
          <p class="text-xs text-muted-foreground">Comma-separated.</p>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label>Photo</Label>
          <div class="flex items-center gap-3">
            <img v-if="photoUrl" :src="photoUrl" alt="" class="h-16 w-16 rounded-md border object-cover" />
            <div v-else class="flex h-16 w-16 items-center justify-center rounded-md border text-xs text-muted-foreground">None</div>
            <Button type="button" variant="outline" size="sm" @click="pickPhoto">{{ photoUrl ? 'Change photo' : 'Choose photo' }}</Button>
            <Button v-if="photoUrl" type="button" variant="ghost" size="sm" @click="removePhoto">Remove</Button>
          </div>
        </div>
      </template>

      <div class="grid grid-cols-2 gap-4">
        <!-- Hidden for Event — its duration comes from each occurrence's own
             end − start on the next step, not one fixed value here
             (DETAILED-SPEC.md §A2/A3 field matrix). -->
        <div v-if="type !== 'event'" class="flex flex-col gap-1.5">
          <Label for="wizard-item-duration">{{ durationLabel }}</Label>
          <Input id="wizard-item-duration" v-model.number="form.duration_minutes" type="number" min="5" step="5" />
        </div>
        <!-- Hidden/fixed at 1 for Appointment (same field matrix). -->
        <div v-if="type !== 'appointment'" class="flex flex-col gap-1.5">
          <Label for="wizard-item-capacity">{{ capacityLabel }}</Label>
          <Input id="wizard-item-capacity" v-model.number="form.capacity" type="number" min="1" step="1" />
        </div>
      </div>

      <!-- Location / online meeting link (Phase 1.6, DETAILED-SPEC.md
           §1.1/§A2) — free-text on every type, distinct from Pro's
           structured multi-branch location_id. -->
      <div class="grid grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="wizard-item-location">Location</Label>
          <Input id="wizard-item-location" v-model="form.location" type="text" placeholder="e.g. 123 Main St, or Online" />
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="wizard-item-meeting-link">Meeting link</Label>
          <Input id="wizard-item-meeting-link" v-model="form.meeting_link" type="url" placeholder="https://zoom.us/…" />
        </div>
      </div>

      <div v-if="!compact" class="flex flex-col gap-1.5">
        <Label>Status</Label>
        <Select v-model="form.status">
          <SelectTrigger class="h-9"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
            <SelectItem value="archived">Archived</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">Back</Button>
      <Button @click="continueStep">Continue</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

// The one Details step for every bookable-item flow: the Add/Edit popup
// (BookableItemWizard.vue, full field set) and onboarding (SetupWizard.vue,
// `compact`). Add and Edit are deliberately the SAME form — an edit walks
// the same steps with everything pre-filled from `initial`.
const props = defineProps({
  type: { type: String, required: true },
  // The parent wizard's accumulated data from an earlier pass (or the
  // loaded item, on edit), so Back — or opening an existing item — shows
  // what's already there instead of the per-type defaults. The wizard
  // v-if-mounts each step, so local state alone is gone by then.
  initial: { type: Object, default: null },
  // Onboarding keeps this to the essentials — no tags/photo/status. Those
  // stay editable from Bookable Items afterwards.
  compact: { type: Boolean, default: false },
});
const emit = defineEmits(['next', 'back']);

// Labels per DETAILED-SPEC.md §A2/A3's per-type field matrix.
const TYPE_LABELS = { appointment: 'Appointment', table: 'Table', event: 'Event', slot: 'Slot' };
const typeLabel = computed(() => TYPE_LABELS[props.type] || props.type);

const DURATION_LABELS = { appointment: 'Appointment length (minutes)', table: 'Reservation length (minutes)', slot: 'Slot length (minutes)' };
const durationLabel = computed(() => DURATION_LABELS[props.type] || 'Duration (minutes)');

const CAPACITY_LABELS = { table: 'Seats', event: 'Total tickets/seats', slot: 'Spots per slot' };
const capacityLabel = computed(() => CAPACITY_LABELS[props.type] || 'Capacity');

// "Staff Member 1" matches DETAILED-SPEC.md §A1's per-type sensible-default
// convention (the same naming FirstBookableItemStep used for Appointment).
const DEFAULT_NAMES = { appointment: 'Staff Member 1', table: 'Table 1', event: 'My Event', slot: 'Bookable Item 1' };

// An empty name, or one that is still ANOTHER type's untouched default
// (owner picked Appointment, saw "Staff Member 1", went Back and switched
// to Table), gets this type's default — a name the owner actually typed
// is kept as-is.
function seedName() {
  const name = props.initial?.name ?? '';
  const isOtherTypeDefault = Object.entries(DEFAULT_NAMES).some(([t, d]) => t !== props.type && d === name);
  return !name || isOtherTypeDefault ? DEFAULT_NAMES[props.type] || '' : name;
}

const form = ref({
  name: seedName(),
  description: props.initial?.description ?? '',
  tags: props.initial?.tags ?? '',
  photo_id: props.initial?.photo_id ?? null,
  duration_minutes: props.initial?.duration_minutes ?? 30,
  capacity: props.initial?.capacity ?? 1,
  location: props.initial?.location ?? '',
  meeting_link: props.initial?.meeting_link ?? '',
  status: props.initial?.status ?? 'active',
});
const error = ref('');

function continueStep() {
  error.value = '';
  if (!form.value.name.trim()) {
    error.value = 'Please enter a name.';
    return;
  }

  // Mirrors BYZO_Bookable_Item::validate() (duration >= 5, capacity >= 1)
  // so a bad value is caught here, next to its field, instead of surfacing
  // as a server error at the Review step — 3 steps away from where it can
  // be fixed.
  if (props.type !== 'event') {
    const duration = Number(form.value.duration_minutes);
    if (!Number.isFinite(duration) || duration < 5) {
      error.value = 'Duration must be at least 5 minutes.';
      return;
    }
  }
  if (props.type !== 'appointment') {
    const capacity = Number(form.value.capacity);
    if (!Number.isInteger(capacity) || capacity < 1) {
      error.value = `${capacityLabel.value} must be a whole number of at least 1.`;
      return;
    }
  }

  const link = form.value.meeting_link.trim();
  if (link && !/^https?:\/\//i.test(link)) {
    error.value = 'Meeting link must start with http:// or https://.';
    return;
  }

  emit('next', { ...form.value, meeting_link: link });
}

// ---- Photo (wp.media) ---------------------------------------------------
//
// form.photo_id is the only value that round-trips to the server —
// photoUrl is purely local display state, resolved once on mount from the
// id already loaded, and set directly from the picked attachment's own URL
// on selection so choosing a photo doesn't need a second REST round-trip
// just to preview it.
const rootEl = ref(null);
const photoUrl = ref('');
let mediaFrame = null;

onMounted(async () => {
  if (props.compact || !form.value.photo_id) {
    return;
  }
  try {
    const media = await window.wp.apiFetch({ path: `/wp/v2/media/${form.value.photo_id}` });
    photoUrl.value = media.media_details?.sizes?.thumbnail?.source_url || media.source_url || '';
  } catch (e) {
    // Attachment deleted from the media library out-of-band — fall back
    // to the "None" placeholder rather than a broken image.
    photoUrl.value = '';
  }
});

onBeforeUnmount(() => {
  // The frame's modal was attached inside the dialog (see pickPhoto) —
  // drop it with this step so it doesn't pile up across Back/Continue.
  try {
    mediaFrame?.modal?.remove();
  } catch (e) {
    // Best effort; a leftover hidden element is harmless.
  }
  mediaFrame = null;
});

function pickPhoto() {
  if (!window.wp?.media) {
    return;
  }
  if (!mediaFrame) {
    mediaFrame = window.wp.media({
      title: 'Select a photo',
      button: { text: 'Use this photo' },
      library: { type: 'image' },
      multiple: false,
    });

    // This step renders inside a modal Dialog (reka-ui), which sets
    // pointer-events:none on <body> and traps focus inside the dialog.
    // wp.media appends its modal to <body> by default, so it would be
    // unclickable and every click into it would bounce focus back. Attach
    // it inside the dialog element instead: it is still position:fixed so
    // it covers the viewport, but now counts as "inside" for both. The
    // wizard also ignores Esc while wp.media is open (body.modal-open).
    const dialog = rootEl.value?.$el?.closest?.('[role="dialog"]');
    if (dialog && mediaFrame.modal?.options) {
      mediaFrame.modal.options.container = dialog;
    }

    mediaFrame.on('select', () => {
      const attachment = mediaFrame.state().get('selection').first().toJSON();
      form.value.photo_id = attachment.id;
      photoUrl.value = attachment.sizes?.thumbnail?.url || attachment.url;
    });
  }
  mediaFrame.open();
}

function removePhoto() {
  form.value.photo_id = null;
  photoUrl.value = '';
}
</script>
