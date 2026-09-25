<template>
  <Dialog :open="true" @update:open="(v) => { if (!v) $emit('close'); }">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>{{ __('New booking', 'bookyzo') }}</DialogTitle>
        <DialogDescription>{{ __('For a phone or walk-in customer.', 'bookyzo') }}</DialogDescription>
      </DialogHeader>
      <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="mb-item">{{ __('Bookable item', 'bookyzo') }}</Label>
          <Select v-model="selectedBookableItemId">
            <SelectTrigger id="mb-item" class="h-9"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="item in items" :key="item.id" :value="item.id">{{ item.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <!-- Pro services this staff member offers (QA report 2026-09-22
             B9) — absent when there are none, which is always the case
             without Pro. "None" books at the item's own price. -->
        <div v-if="servicesForItem.length" class="flex flex-col gap-1.5">
          <Label for="mb-service">{{ __('Service', 'bookyzo') }}</Label>
          <Select v-model="selectedServiceId">
            <SelectTrigger id="mb-service" class="h-9"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="NO_SERVICE">{{ __('None', 'bookyzo') }}</SelectItem>
              <SelectItem v-for="service in servicesForItem" :key="service.id" :value="service.id">{{ service.label }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="mb-slot">{{ __('Time slot', 'bookyzo') }}</Label>
          <Select v-model="selectedSlotId" :disabled="loadingSlots || slots.length === 0">
            <SelectTrigger id="mb-slot" class="h-9">
              <SelectValue :placeholder="loadingSlots ? __('Loading…', 'bookyzo') : (slots.length === 0 ? __('No available slots in the next 2 weeks', 'bookyzo') : undefined)" />
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

        <!-- Ticket tiers, add-ons and custom fields, when the chosen item has
             any — the widget collected all three but a booking taken over the
             phone couldn't (QA report §4.3). Absent otherwise, including on
             every site without Pro. -->
        <div v-if="ticketTiers.length" class="flex flex-col gap-1.5">
          <Label for="mb-tier">{{ __('Ticket type', 'bookyzo') }}</Label>
          <Select v-model="form.ticket_tier_id">
            <SelectTrigger id="mb-tier" class="h-9"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="tier in ticketTiers" :key="tier.id" :value="tier.id">{{ tier.name }} — {{ formatMoney(tier.price) }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="addons.length" class="flex flex-col gap-1.5">
          <Label>{{ __('Add-ons', 'bookyzo') }}</Label>
          <label v-for="addon in addons" :key="addon.id" class="flex items-center justify-between gap-2 text-sm">
            <span class="flex items-center gap-2">
              <input v-model="form.addon_ids" type="checkbox" :value="addon.id" class="h-4 w-4" />
              {{ addon.name }}
            </span>
            <span class="text-muted-foreground">{{ formatMoney(addon.price) }}</span>
          </label>
        </div>

        <div v-for="field in customFields" :key="field.id" class="flex flex-col gap-1.5">
          <label v-if="field.field_type === 'checkbox'" class="flex items-center gap-2 text-sm">
            <input v-model="form.custom_fields[field.id]" type="checkbox" class="h-4 w-4" />
            {{ field.label }}
          </label>
          <template v-else>
            <Label :for="`mb-cf-${field.id}`">{{ field.label }}<span v-if="field.required"> *</span></Label>
            <Select v-if="field.field_type === 'dropdown'" v-model="form.custom_fields[field.id]">
              <SelectTrigger :id="`mb-cf-${field.id}`" class="h-9"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="choice in field.options?.choices || []" :key="choice" :value="choice">{{ choice }}</SelectItem>
              </SelectContent>
            </Select>
            <Input
              v-else
              :id="`mb-cf-${field.id}`"
              v-model="form.custom_fields[field.id]"
              :type="{ date: 'date', number: 'number' }[field.field_type] || 'text'"
            />
          </template>
        </div>

        <!-- The same server-computed total the customer would see
             (POST /bookings/quote), so staff quote the real price. -->
        <div v-if="quote && quote.payment_amount !== null" class="flex items-center justify-between rounded-md bg-muted/50 px-3 py-2 text-sm">
          <span class="text-muted-foreground">{{ __('Total', 'bookyzo') }}</span>
          <span class="font-medium">{{ formatMoney(quote.payment_amount) }}</span>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="mb-name">{{ __('Customer name', 'bookyzo') }}</Label>
          <Input id="mb-name" v-model="form.customer_name" type="text" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div class="flex flex-col gap-1.5">
            <Label for="mb-email">{{ __('Email', 'bookyzo') }}</Label>
            <Input id="mb-email" v-model="form.customer_email" type="email" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="mb-phone">{{ __('Phone', 'bookyzo') }}</Label>
            <Input id="mb-phone" v-model="form.customer_phone" type="tel" />
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="mb-notes">{{ __('Notes', 'bookyzo') }}</Label>
          <Textarea id="mb-notes" v-model="form.customer_notes" rows="2" />
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </div>
      <DialogFooter>
        <Button variant="outline" @click="$emit('close')">{{ __('Cancel', 'bookyzo') }}</Button>
        <Button :disabled="!selectedSlotId || !form.customer_name || !form.customer_email || saving" @click="save">
          {{ saving ? __('Booking…', 'bookyzo') : __('Create booking', 'bookyzo') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<script setup>
import { i18n, locale } from '@/lib/i18n';
import { ref, computed, watch, onMounted } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { api } from '@/api/client';
import { formatMoney } from '@/lib/money';

// Same POST /bookings the public widget uses, per DETAILED-SPEC.md §A4 —
// the only difference from the guest flow is this request carries an
// authenticated admin session instead of running through the public
// permission check, which the REST layer already handles transparently
// (BYZO_REST_Controller::public_permissions_check allows both).
const props = defineProps({
  initialBookableItemId: { type: [String, Number], default: null },
});
const emit = defineEmits(['close', 'created']);

const items = ref([]);
const selectedBookableItemId = ref(props.initialBookableItemId);
const slots = ref([]);
const selectedSlotId = ref(null);
// Select can't hold null, so "no service" is a sentinel value.
const NO_SERVICE = 0;
const services = ref([]);
const selectedServiceId = ref(NO_SERVICE);
const loadingSlots = ref(false);
const saving = ref(false);
const error = ref('');

const ticketTiers = ref([]);
const addons = ref([]);
const customFields = ref([]);
const quote = ref(null);

const form = ref({
  party_size: 1,
  customer_name: '',
  customer_email: '',
  customer_phone: '',
  customer_notes: '',
  ticket_tier_id: null,
  addon_ids: [],
  custom_fields: {},
});

// Same party-size input as the public widget's StepDetails.vue — this
// modal posts to the exact same POST /bookings route (DETAILED-SPEC.md
// §A4), so it needs the same type-aware label/hidden-for-Appointment/
// max-by-remaining-capacity behavior.
const PARTY_SIZE_LABELS = {
  table: i18n.__('Party size', 'bookyzo'),
  event: i18n.__('Tickets', 'bookyzo'),
  slot: i18n.__('Spots', 'bookyzo'),
};
const selectedItem = computed(() => items.value.find((i) => i.id === selectedBookableItemId.value));
const selectedSlot = computed(() => slots.value.find((s) => s.id === selectedSlotId.value));
const showPartySize = computed(() => selectedItem.value && selectedItem.value.type !== 'appointment');
const partySizeLabel = computed(() => PARTY_SIZE_LABELS[selectedItem.value?.type] || i18n.__('Party size', 'bookyzo'));
const partySizeMax = computed(() => Math.max(1, selectedSlot.value?.capacity_remaining || 1));

// /services/public is Pro's route; without Pro it 404s and there are simply
// no services to offer here.
async function loadServices() {
  services.value = await api.get('/services/public').catch(() => []);
}

const servicesForItem = computed(() =>
  services.value
    .map((service) => {
      const member = (service.staff || []).find((m) => m.id === selectedBookableItemId.value);
      return member ? { id: service.id, label: member.price !== null ? `${service.name} — ${formatMoney(member.price)}` : service.name } : null;
    })
    .filter(Boolean)
);

async function loadItems() {
  items.value = await api.get('/bookable-items?status=active&per_page=100');
  if (!selectedBookableItemId.value && items.value.length > 0) {
    selectedBookableItemId.value = items.value[0].id;
  }
}

// Pro-defined extras for the chosen item; every one of these routes
// returns an empty list without Pro, so this is simply nothing to render.
async function loadExtras() {
  ticketTiers.value = [];
  addons.value = [];
  customFields.value = [];
  form.value.ticket_tier_id = null;
  form.value.addon_ids = [];
  form.value.custom_fields = {};

  const id = selectedBookableItemId.value;
  if (!id) {
    return;
  }

  const [tiers, addonList, fields] = await Promise.all([
    selectedItem.value?.type === 'event' ? api.get(`/ticket-tiers/public?bookable_item_id=${id}`).catch(() => []) : Promise.resolve([]),
    api.get(`/addons/public?bookable_item_id=${id}`).catch(() => []),
    api.get(`/custom-fields/public?bookable_item_id=${id}`).catch(() => []),
  ]);
  ticketTiers.value = tiers;
  addons.value = addonList;
  customFields.value = fields;
  for (const field of fields) {
    form.value.custom_fields[field.id] = field.field_type === 'checkbox' ? false : '';
  }
}

// What this booking would cost, priced by the server exactly as it will be
// charged — the modal used to show no price at all.
let quoteTimer = null;
async function refreshQuote() {
  if (!selectedSlotId.value) {
    quote.value = null;
    return;
  }
  try {
    quote.value = await api.post('/bookings/quote', {
      slot_id: selectedSlotId.value,
      party_size: form.value.party_size,
      service_id: selectedServiceId.value || undefined,
      ticket_tier_id: form.value.ticket_tier_id,
      addon_ids: form.value.addon_ids,
    });
  } catch (e) {
    quote.value = null;
  }
}

async function loadSlots() {
  if (!selectedBookableItemId.value) {
    slots.value = [];
    return;
  }
  loadingSlots.value = true;
  selectedSlotId.value = null;
  try {
    slots.value = await api.get(`/slots?bookable_item_id=${selectedBookableItemId.value}`);
  } catch (e) {
    slots.value = [];
  } finally {
    loadingSlots.value = false;
  }
}

function formatSlot(slot) {
  const start = new Date(slot.start_datetime);
  return start.toLocaleString(locale, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

async function save() {
  error.value = '';
  saving.value = true;
  try {
    const booking = await api.post('/bookings', {
      slot_id: selectedSlotId.value,
      service_id: selectedServiceId.value || undefined,
      ...form.value,
    });
    quote.value = null;
    emit('created', booking);
  } catch (e) {
    error.value = e.message || i18n.__('Could not create this booking.', 'bookyzo');
  } finally {
    saving.value = false;
  }
}

watch(selectedBookableItemId, () => {
  selectedServiceId.value = NO_SERVICE;
  loadSlots();
  loadExtras();
});

// Anything that changes the price re-quotes, debounced like the widget's own.
watch(
  () => [selectedSlotId.value, selectedServiceId.value, form.value.party_size, form.value.ticket_tier_id, form.value.addon_ids.join(',')],
  () => {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(refreshQuote, 200);
  }
);
// A newly picked slot may have less remaining capacity than whatever was
// typed for the previous one — reset rather than silently clamp, so the
// customer's actual pick decision isn't guessed at.
watch(selectedSlotId, () => {
  form.value.party_size = 1;
});
onMounted(async () => {
  await Promise.all([loadItems(), loadServices()]);
  await Promise.all([loadSlots(), loadExtras()]);
});
</script>
