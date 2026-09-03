<template>
  <Dialog :open="true" @update:open="(v) => { if (!v) $emit('close'); }">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>New booking</DialogTitle>
        <DialogDescription>For a phone or walk-in customer.</DialogDescription>
      </DialogHeader>
      <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="mb-resource">Resource</Label>
          <Select v-model="selectedResourceId">
            <SelectTrigger id="mb-resource" class="h-9"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="resource in resources" :key="resource.id" :value="resource.id">{{ resource.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="mb-slot">Time slot</Label>
          <Select v-model="selectedSlotId" :disabled="loadingSlots || slots.length === 0">
            <SelectTrigger id="mb-slot" class="h-9">
              <SelectValue :placeholder="loadingSlots ? 'Loading…' : (slots.length === 0 ? 'No available slots in the next 2 weeks' : undefined)" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="slot in slots" :key="slot.id" :value="slot.id">{{ formatSlot(slot) }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="showPartySize" class="flex flex-col gap-1.5">
          <Label for="mb-party-size">{{ partySizeLabel }}</Label>
          <Input id="mb-party-size" v-model.number="form.party_size" type="number" min="1" :max="partySizeMax" />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="mb-name">Customer name</Label>
          <Input id="mb-name" v-model="form.customer_name" type="text" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div class="flex flex-col gap-1.5">
            <Label for="mb-email">Email</Label>
            <Input id="mb-email" v-model="form.customer_email" type="email" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="mb-phone">Phone</Label>
            <Input id="mb-phone" v-model="form.customer_phone" type="tel" />
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="mb-notes">Notes</Label>
          <Textarea id="mb-notes" v-model="form.customer_notes" rows="2" />
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </div>
      <DialogFooter>
        <Button variant="outline" @click="$emit('close')">Cancel</Button>
        <Button :disabled="!selectedSlotId || !form.customer_name || !form.customer_email || saving" @click="save">
          {{ saving ? 'Booking…' : 'Create booking' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { api } from '@/api/client';

// Same POST /bookings the public widget uses, per DETAILED-SPEC.md §A4 —
// the only difference from the guest flow is this request carries an
// authenticated admin session instead of running through the public
// permission check, which the REST layer already handles transparently
// (BYZO_REST_Controller::public_permissions_check allows both).
const props = defineProps({
  initialResourceId: { type: [String, Number], default: null },
});
const emit = defineEmits(['close', 'created']);

const resources = ref([]);
const selectedResourceId = ref(props.initialResourceId);
const slots = ref([]);
const selectedSlotId = ref(null);
const loadingSlots = ref(false);
const saving = ref(false);
const error = ref('');

const form = ref({
  party_size: 1,
  customer_name: '',
  customer_email: '',
  customer_phone: '',
  customer_notes: '',
});

// Same party-size input as the public widget's StepDetails.vue — this
// modal posts to the exact same POST /bookings route (DETAILED-SPEC.md
// §A4), so it needs the same type-aware label/hidden-for-Appointment/
// max-by-remaining-capacity behavior.
const PARTY_SIZE_LABELS = {
  table: 'Party size',
  event: 'Tickets',
  slot: 'Spots',
};
const selectedResource = computed(() => resources.value.find((r) => r.id === selectedResourceId.value));
const selectedSlot = computed(() => slots.value.find((s) => s.id === selectedSlotId.value));
const showPartySize = computed(() => selectedResource.value && selectedResource.value.type !== 'appointment');
const partySizeLabel = computed(() => PARTY_SIZE_LABELS[selectedResource.value?.type] || 'Party size');
const partySizeMax = computed(() => Math.max(1, selectedSlot.value?.capacity_remaining || 1));

async function loadResources() {
  resources.value = await api.get('/resources?status=active&per_page=100');
  if (!selectedResourceId.value && resources.value.length > 0) {
    selectedResourceId.value = resources.value[0].id;
  }
}

async function loadSlots() {
  if (!selectedResourceId.value) {
    slots.value = [];
    return;
  }
  loadingSlots.value = true;
  selectedSlotId.value = null;
  try {
    slots.value = await api.get(`/slots?resource_id=${selectedResourceId.value}`);
  } catch (e) {
    slots.value = [];
  } finally {
    loadingSlots.value = false;
  }
}

function formatSlot(slot) {
  const start = new Date(slot.start_datetime);
  return start.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

async function save() {
  error.value = '';
  saving.value = true;
  try {
    const booking = await api.post('/bookings', {
      slot_id: selectedSlotId.value,
      ...form.value,
    });
    emit('created', booking);
  } catch (e) {
    error.value = e.message || 'Could not create this booking.';
  } finally {
    saving.value = false;
  }
}

watch(selectedResourceId, loadSlots);
// A newly picked slot may have less remaining capacity than whatever was
// typed for the previous one — reset rather than silently clamp, so the
// customer's actual pick decision isn't guessed at.
watch(selectedSlotId, () => {
  form.value.party_size = 1;
});
onMounted(async () => {
  await loadResources();
  await loadSlots();
});
</script>
