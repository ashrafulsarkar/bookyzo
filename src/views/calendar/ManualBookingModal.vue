<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="$emit('close')">
    <Card class="w-full max-w-md">
      <CardHeader>
        <CardTitle>New booking</CardTitle>
        <CardDescription>For a phone or walk-in customer.</CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="mb-resource">Resource</label>
          <select
            id="mb-resource"
            v-model="selectedResourceId"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <option v-for="resource in resources" :key="resource.id" :value="resource.id">{{ resource.name }}</option>
          </select>
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="mb-slot">Time slot</label>
          <select
            id="mb-slot"
            v-model="selectedSlotId"
            :disabled="loadingSlots || slots.length === 0"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <option v-if="loadingSlots" value="">Loading…</option>
            <option v-else-if="slots.length === 0" value="">No available slots in the next 2 weeks</option>
            <option v-for="slot in slots" :key="slot.id" :value="slot.id">{{ formatSlot(slot) }}</option>
          </select>
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="mb-name">Customer name</label>
          <input
            id="mb-name"
            v-model="form.customer_name"
            type="text"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="mb-email">Email</label>
            <input
              id="mb-email"
              v-model="form.customer_email"
              type="email"
              class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="mb-phone">Phone</label>
            <input
              id="mb-phone"
              v-model="form.customer_phone"
              type="tel"
              class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="mb-notes">Notes</label>
          <textarea
            id="mb-notes"
            v-model="form.customer_notes"
            rows="2"
            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </CardContent>
      <CardFooter class="justify-between">
        <Button variant="outline" @click="$emit('close')">Cancel</Button>
        <Button :disabled="!selectedSlotId || !form.customer_name || !form.customer_email || saving" @click="save">
          {{ saving ? 'Booking…' : 'Create booking' }}
        </Button>
      </CardFooter>
    </Card>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
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
const selectedSlotId = ref('');
const loadingSlots = ref(false);
const saving = ref(false);
const error = ref('');

const form = ref({
  customer_name: '',
  customer_email: '',
  customer_phone: '',
  customer_notes: '',
});

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
  selectedSlotId.value = '';
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
onMounted(async () => {
  await loadResources();
  await loadSlots();
});
</script>
