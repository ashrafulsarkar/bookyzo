<template>
  <Card class="max-w-xl">
    <CardContent class="flex flex-col gap-4 pt-6">
      <!-- Read-only — type is fixed at creation (DETAILED-SPEC.md §5 item 6:
           "changing type would orphan the resource's schedule/slots"),
           enforced server-side too (BYZO_REST_Resources_Controller::update_item()). -->
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

      <div class="grid grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="duration">Duration (minutes)</Label>
          <Input id="duration" v-model.number="form.duration_minutes" type="number" min="5" step="5" />
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="price">Price</Label>
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
import { computed } from 'vue';

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

// photo field deferred (ROADMAP.md Phase 1 step 2 note) — needs a WP
// media-library picker (wp.media frame), not just a plain input; tracked
// as a follow-up, not stubbed with a fake upload control here.
</script>
