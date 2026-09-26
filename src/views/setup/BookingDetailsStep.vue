<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ title }}</CardTitle>
      <CardDescription>{{ description }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <!-- Edit only — type is fixed at creation (DETAILED-SPEC.md §5 item 6:
           "changing type would orphan the item's schedule/slots"), enforced
           server-side by BYZO_REST_Bookable_Items_Controller::update_item(). -->
      <div v-if="isEdit" class="flex flex-col gap-1.5">
        <Label>{{ __('Type', 'bookyzo') }}</Label>
        <div><Badge variant="secondary">{{ typeLabel }}</Badge></div>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="wizard-item-name">{{ __('Name', 'bookyzo') }}</Label>
        <Input id="wizard-item-name" v-model="form.name" type="text" />
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="wizard-item-description">{{ __('Description (optional)', 'bookyzo') }}</Label>
        <Textarea id="wizard-item-description" v-model="form.description" rows="2" />
      </div>

      <!-- Photo is edit-only: the quick-add flows deliberately defer it
           ("you can add a photo and more later") to keep the first pass
           short. -->
      <template v-if="isEdit">
        <div class="flex flex-col gap-1.5">
          <Label>{{ __('Photo', 'bookyzo') }}</Label>
          <div class="flex items-center gap-3">
            <img v-if="photoUrl" :src="photoUrl" alt="" class="h-16 w-16 rounded-md border object-cover" />
            <div v-else class="flex h-16 w-16 items-center justify-center rounded-md border text-xs text-muted-foreground">{{ __('None', 'bookyzo') }}</div>
            <Button type="button" variant="outline" size="sm" @click="pickPhoto">{{ photoUrl ? __('Change photo', 'bookyzo') : __('Choose photo', 'bookyzo') }}</Button>
            <Button v-if="photoUrl" type="button" variant="ghost" size="sm" @click="removePhoto">{{ __('Remove', 'bookyzo') }}</Button>
          </div>
        </div>
      </template>

      <!-- Hidden for Event — its duration comes from each occurrence's own
           end − start on the Dates step, not one fixed value here
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

      <!-- Location / online meeting link (Phase 1.6, DETAILED-SPEC.md
           §1.1/§A2) — free-text on every type, distinct from Pro's
           structured multi-branch location_id. -->
      <div class="grid grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="wizard-item-location">{{ __('Location (optional)', 'bookyzo') }}</Label>
          <Input id="wizard-item-location" v-model="form.location" type="text" :placeholder="__('e.g. 123 Main St, or Online', 'bookyzo')" />
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="wizard-item-meeting-link">{{ __('Meeting link (optional)', 'bookyzo') }}</Label>
          <Input id="wizard-item-meeting-link" v-model="form.meeting_link" type="url" placeholder="https://zoom.us/…" />
        </div>
      </div>

      <div v-if="isEdit" class="flex flex-col gap-1.5">
        <Label>{{ __('Status', 'bookyzo') }}</Label>
        <Select v-model="form.status">
          <SelectTrigger class="h-9"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="active">{{ __('Active', 'bookyzo') }}</SelectItem>
            <SelectItem value="inactive">{{ __('Inactive', 'bookyzo') }}</SelectItem>
            <SelectItem value="archived">{{ __('Archived', 'bookyzo') }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">{{ backLabel }}</Button>
      <Button @click="continueStep">{{ __('Continue', 'bookyzo') }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
import { ref, computed, onMounted } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

const props = defineProps({
  type: { type: String, required: true },
  // The parent wizard's accumulated data from an earlier pass (or, for
  // edit, the loaded item), so Back shows what the owner already typed
  // instead of the per-type defaults. Optional — a first visit passes
  // nothing.
  initial: { type: Object, default: null },
  // Which flow this step sits in. 'setup' (onboarding) and 'add' (the
  // Bookable Items popup) collect the short field set; 'edit' adds the
  // type badge, photo, and status — the fields the quick flows
  // defer with "you can add a photo and more later".
  context: { type: String, default: 'setup' },
  backLabel: { type: String, default: i18n.__('Back', 'bookyzo') },
});
const emit = defineEmits(['next', 'back']);

const isEdit = computed(() => props.context === 'edit');

// Per-type labels per DETAILED-SPEC.md §A2/A3's field matrix — kept in
// sync by hand with the type pickers' option lists.
const TYPE_LABELS = { appointment: i18n.__('Appointment', 'bookyzo'), table: i18n.__('Table', 'bookyzo'), event: i18n.__('Event', 'bookyzo'), slot: i18n.__('Slot', 'bookyzo') };
const typeLabel = computed(() => TYPE_LABELS[props.type] || props.type);

const DURATION_LABELS = { appointment: i18n.__('Appointment length (minutes)', 'bookyzo'), table: i18n.__('Reservation length (minutes)', 'bookyzo'), slot: i18n.__('Slot length (minutes)', 'bookyzo') };
const durationLabel = computed(() => DURATION_LABELS[props.type] || i18n.__('Duration (minutes)', 'bookyzo'));

const CAPACITY_LABELS = { table: i18n.__('Seats', 'bookyzo'), event: i18n.__('Total tickets/seats', 'bookyzo'), slot: i18n.__('Spots per slot', 'bookyzo') };
const capacityLabel = computed(() => CAPACITY_LABELS[props.type] || i18n.__('Capacity', 'bookyzo'));

const title = computed(() => {
  if (isEdit.value) return i18n.__('Details', 'bookyzo');
  const titles = props.context === 'add'
    ? { appointment: i18n.__('Tell us about this appointment', 'bookyzo'), table: i18n.__('Tell us about this table', 'bookyzo'), event: i18n.__('Tell us about this event', 'bookyzo'), slot: i18n.__('Tell us about this slot', 'bookyzo') }
    : { appointment: i18n.__('Tell us about your first appointment', 'bookyzo'), table: i18n.__('Tell us about your first table', 'bookyzo'), event: i18n.__('Tell us about your first event', 'bookyzo'), slot: i18n.__('Tell us about your first slot', 'bookyzo') };
  return titles[props.type] || titles.appointment;
});
const description = computed(() =>
  isEdit.value ? i18n.__('Name, photo, and where it happens.', 'bookyzo') : i18n.__('You can add a photo and more later.', 'bookyzo')
);

// "Staff Member 1" matches DETAILED-SPEC.md §A1's per-type sensible-default
// convention (the same naming FirstBookableItemStep used for Appointment).
const DEFAULT_NAMES = { appointment: i18n.__('Staff Member 1', 'bookyzo'), table: i18n.__('Table 1', 'bookyzo'), event: i18n.__('My Event', 'bookyzo'), slot: i18n.__('Bookable Item 1', 'bookyzo') };

// Create flows only: an empty name, or one that is still ANOTHER type's
// untouched default (owner picked Appointment, saw "Staff Member 1", went
// Back and switched to Table), gets this type's default — a name the owner
// actually typed is kept. Edit never second-guesses the saved name.
function seedName() {
  const name = props.initial?.name ?? '';
  if (isEdit.value) return name;
  const isOtherTypeDefault = Object.entries(DEFAULT_NAMES).some(([t, d]) => t !== props.type && d === name);
  return !name || isOtherTypeDefault ? DEFAULT_NAMES[props.type] || '' : name;
}

const form = ref({
  name: seedName(),
  description: props.initial?.description ?? '',
  duration_minutes: props.initial?.duration_minutes ?? 30,
  capacity: props.initial?.capacity ?? 1,
  location: props.initial?.location ?? '',
  meeting_link: props.initial?.meeting_link ?? '',
  photo_id: props.initial?.photo_id ?? null,
  status: props.initial?.status ?? 'active',
});
const error = ref('');

// wp.media frame (edit only). form.photo_id is the only value that
// round-trips to the server — photoUrl is purely local display state,
// resolved once on mount from the loaded id, and set straight from the
// picked attachment's own URL on selection so choosing a photo doesn't
// need a second REST round-trip just to preview it.
const photoUrl = ref('');
let mediaFrame = null;

onMounted(async () => {
  if (!isEdit.value || !form.value.photo_id) {
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

function pickPhoto() {
  if (!window.wp?.media) {
    return;
  }
  if (!mediaFrame) {
    mediaFrame = window.wp.media({
      title: i18n.__('Select a photo', 'bookyzo'),
      button: { text: i18n.__('Use this photo', 'bookyzo') },
      library: { type: 'image' },
      multiple: false,
    });
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

function continueStep() {
  error.value = '';
  if (!form.value.name.trim()) {
    error.value = i18n.__('Please enter a name.', 'bookyzo');
    return;
  }

  // Mirrors BYZO_Bookable_Item::validate() (duration >= 5, capacity >= 1)
  // so a bad value is caught here, next to its field, instead of surfacing
  // as a server error at the Review step — steps away from where it can
  // be fixed.
  if (props.type !== 'event') {
    const duration = Number(form.value.duration_minutes);
    if (!Number.isFinite(duration) || duration < 5) {
      error.value = i18n.__('Duration must be at least 5 minutes.', 'bookyzo');
      return;
    }
  }
  if (props.type !== 'appointment') {
    const capacity = Number(form.value.capacity);
    if (!Number.isInteger(capacity) || capacity < 1) {
      error.value = i18n.sprintf(/*! translators: %s: name of the field, e.g. Capacity or Party size. */ i18n.__('%s must be a whole number of at least 1.', 'bookyzo'), capacityLabel.value);
      return;
    }
  }

  emit('next', { ...form.value });
}
</script>
