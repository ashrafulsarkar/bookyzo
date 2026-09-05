<template>
  <Card class="max-w-xl">
    <CardContent class="flex flex-col gap-4 pt-6">
      <!-- Read-only — type is fixed at creation (DETAILED-SPEC.md §5 item 6:
           "changing type would orphan the item's schedule/slots"),
           enforced server-side too (BYZO_REST_Bookable_Items_Controller::update_item()). -->
      <div class="flex flex-col gap-1.5">
        <Label>Type</Label>
        <div><Badge variant="secondary">{{ typeLabel }}</Badge></div>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="name">Name</Label>
        <Input id="name" v-model="form.name" type="text" />
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="description">Description</Label>
        <Textarea id="description" v-model="form.description" rows="3" />
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="tags">Tags</Label>
        <Input id="tags" v-model="form.tags" type="text" placeholder="e.g. haircut, senior stylist" />
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

      <div class="grid grid-cols-2 gap-4">
        <!-- Hidden for Event (DETAILED-SPEC.md §A2/A3 field matrix): its
             duration comes from each occurrence's own end − start on the
             Dates tab, there's no single fixed duration to set here. -->
        <div v-if="form.type !== 'event'" class="flex flex-col gap-1.5">
          <Label for="duration">Duration (minutes)</Label>
          <Input id="duration" v-model.number="form.duration_minutes" type="number" min="5" step="5" />
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="price">{{ priceLabel }}</Label>
          <Input id="price" v-model="form.price" type="number" min="0" step="0.01" placeholder="0.00" />
        </div>
      </div>

      <!-- Hidden/fixed at 1 for Appointment (DETAILED-SPEC.md §A2/A3 field
           matrix) — nothing to choose yet, so the field doesn't render at
           all rather than showing disabled. -->
      <div v-if="form.type !== 'appointment'" class="flex flex-col gap-1.5">
        <Label for="capacity">{{ capacityLabel }}</Label>
        <Input id="capacity" v-model.number="form.capacity" type="number" min="1" step="1" />
      </div>

      <div class="flex flex-col gap-1.5">
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
      <Button variant="outline" @click="$emit('cancel')">Cancel</Button>
      <Button :disabled="saving" @click="$emit('save')">{{ saving ? 'Saving…' : 'Save' }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { computed, ref, onMounted } from 'vue';

const props = defineProps({
  form: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['save', 'cancel']);

// Labels per DETAILED-SPEC.md §A2/A3's per-type field matrix.
const TYPE_LABELS = {
  appointment: 'Appointment',
  table: 'Table',
  event: 'Event',
  slot: 'Slot',
};
const typeLabel = computed(() => TYPE_LABELS[props.form.type] || props.form.type);

const CAPACITY_LABELS = {
  table: 'Seats',
  event: 'Total tickets/seats',
  slot: 'Spots per slot',
};
const capacityLabel = computed(() => CAPACITY_LABELS[props.form.type] || 'Capacity');

const PRICE_LABELS = {
  event: 'Ticket price',
};
const priceLabel = computed(() => PRICE_LABELS[props.form.type] || 'Price');

// wp.media frame (Phase 1.5 step 7, closing the ROADMAP.md Phase 1 step 2
// deferral). form.photo_id is the only value that round-trips to the
// server — photoUrl is purely local display state, resolved once on
// mount from the id BookableItemForm.vue already loaded (an edit's
// GET /bookable-items/:id response), and set directly from the picked
// attachment's own URL on selection so choosing a photo doesn't need a
// second REST round-trip just to preview it.
const photoUrl = ref('');
let mediaFrame = null;

onMounted(async () => {
  if ( ! props.form.photo_id ) {
    return;
  }
  try {
    const media = await window.wp.apiFetch({ path: `/wp/v2/media/${props.form.photo_id}` });
    photoUrl.value = media.media_details?.sizes?.thumbnail?.source_url || media.source_url || '';
  } catch (e) {
    // Attachment deleted from the media library out-of-band — fall back
    // to the "None" placeholder rather than a broken image.
    photoUrl.value = '';
  }
});

function pickPhoto() {
  if ( ! window.wp?.media ) {
    return;
  }
  if ( ! mediaFrame ) {
    mediaFrame = window.wp.media({
      title: 'Select a photo',
      button: { text: 'Use this photo' },
      library: { type: 'image' },
      multiple: false,
    });
    mediaFrame.on('select', () => {
      const attachment = mediaFrame.state().get('selection').first().toJSON();
      props.form.photo_id = attachment.id;
      photoUrl.value = attachment.sizes?.thumbnail?.url || attachment.url;
    });
  }
  mediaFrame.open();
}

function removePhoto() {
  props.form.photo_id = null;
  photoUrl.value = '';
}
</script>
