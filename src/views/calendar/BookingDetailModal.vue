<template>
  <Dialog :open="true" @update:open="(v) => { if (!v) $emit('close'); }">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <div class="flex items-start justify-between pr-6">
          <div>
            <DialogTitle>{{ booking.customer_name }}</DialogTitle>
            <DialogDescription>{{ timeRangeLabel }}</DialogDescription>
          </div>
          <ByzoBadge :status="booking.status">{{ booking.status.replace('_', ' ') }}</ByzoBadge>
        </div>
      </DialogHeader>
      <div class="flex flex-col gap-2 text-sm">
        <p><span class="text-muted-foreground">Email:</span> {{ booking.customer_email }}</p>
        <p v-if="booking.customer_phone"><span class="text-muted-foreground">Phone:</span> {{ booking.customer_phone }}</p>
        <p v-if="booking.party_size > 1"><span class="text-muted-foreground">Party size:</span> {{ booking.party_size }}</p>
        <p v-if="booking.customer_notes"><span class="text-muted-foreground">Notes:</span> {{ booking.customer_notes }}</p>
        <p v-if="booking.payment_amount !== null" class="flex items-center gap-2">
          <span class="text-muted-foreground">Payment:</span> {{ Number(booking.payment_amount).toFixed(2) }} ({{ booking.payment_status }})
          <Button
            v-if="booking.payment_status === 'unpaid'"
            variant="outline"
            size="sm"
            :disabled="markingPaid"
            @click="markPaid"
          >
            {{ markingPaid ? 'Marking…' : 'Mark as paid' }}
          </Button>
        </p>

        <!-- Basic reschedule (added 2026-09-05, Phase 1.6, DETAILED-SPEC.md
             §1.4/§A4) — only offered for a still-open booking; a
             completed/cancelled/no_show/rejected booking has nothing left
             to move. -->
        <div v-if="canReschedule" class="border-t border-border pt-3">
          <Button v-if="!rescheduling" type="button" variant="outline" size="sm" @click="startReschedule">Reschedule</Button>
          <div v-else class="flex flex-col gap-2">
            <Label for="reschedule-slot" class="text-xs text-muted-foreground">Move to a different time</Label>
            <Select v-model="newSlotId">
              <SelectTrigger id="reschedule-slot" class="h-9">
                <SelectValue :placeholder="loadingSlots ? 'Loading…' : (slots.length === 0 ? 'No other available slots' : 'Choose a time')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="slot in slots" :key="slot.id" :value="slot.id">{{ formatSlot(slot) }}</SelectItem>
              </SelectContent>
            </Select>
            <div class="flex gap-2">
              <Button type="button" size="sm" :disabled="!newSlotId || rescheduleSaving" @click="confirmReschedule">
                {{ rescheduleSaving ? 'Moving…' : 'Move booking' }}
              </Button>
              <Button type="button" variant="ghost" size="sm" @click="rescheduling = false">Cancel</Button>
            </div>
          </div>
        </div>

        <p v-if="error" class="text-destructive">{{ error }}</p>
      </div>
      <DialogFooter class="flex-wrap gap-2">
        <Button variant="outline" @click="$emit('close')">Close</Button>
        <Button
          v-for="transition in allowedTransitions"
          :key="transition"
          :variant="declineTransitions.includes(transition) ? 'outline' : 'default'"
          :class="declineTransitions.includes(transition) && 'text-destructive hover:text-destructive'"
          :disabled="updating"
          @click="changeStatus(transition)"
        >
          {{ label(transition) }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import { api } from '@/api/client';
import { formatTime } from '@/lib/date';

// Mirrors BYZO_Booking::TRANSITIONS server-side (includes/class-byzo-booking.php)
// — the server is the actual source of truth/enforcement, this is just so
// the UI doesn't offer a button that would 400. Kept as a small literal
// map rather than a shared import since PHP/JS can't share a constant
// directly; if the server-side table ever changes, this needs a matching edit.
// `rejected` added 2026-09-05 (Phase 1.6) — reachable only from `pending`,
// same rule as the server's TRANSITIONS table.
const TRANSITIONS = {
  pending: ['confirmed', 'rejected', 'cancelled', 'no_show'],
  confirmed: ['completed', 'cancelled', 'no_show'],
  completed: [],
  cancelled: [],
  no_show: [],
  rejected: [],
};
// Transitions styled as a "decline" action (outline + destructive text)
// rather than the default filled/primary look.
const declineTransitions = ['cancelled', 'no_show', 'rejected'];

const props = defineProps({
  booking: { type: Object, required: true },
});
const emit = defineEmits(['close', 'updated']);

const updating = ref(false);
const markingPaid = ref(false);
const error = ref('');

const allowedTransitions = computed(() => TRANSITIONS[props.booking.status] || []);

// Basic reschedule (added 2026-09-05, Phase 1.6) — offered whenever the
// server would actually allow it (BYZO_Booking::reschedule() enforces the
// same pending/confirmed-only rule).
const canReschedule = computed(() => ['pending', 'confirmed'].includes(props.booking.status));
const rescheduling = ref(false);
const slots = ref([]);
const loadingSlots = ref(false);
const newSlotId = ref(null);
const rescheduleSaving = ref(false);

async function startReschedule() {
  rescheduling.value = true;
  if (slots.value.length === 0) {
    await loadSlotsForReschedule();
  }
}

async function loadSlotsForReschedule() {
  loadingSlots.value = true;
  try {
    const all = await api.get(`/slots?bookable_item_id=${props.booking.bookable_item_id}`);
    // The booking's own current slot is still 'open'-adjacent in the admin's
    // eyes but isn't a real "different time" choice — exclude it.
    slots.value = all.filter((s) => s.id !== props.booking.slot_id);
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

async function confirmReschedule() {
  error.value = '';
  rescheduleSaving.value = true;
  try {
    const updated = await api.put(`/bookings/${props.booking.id}/reschedule`, { slot_id: newSlotId.value });
    rescheduling.value = false;
    newSlotId.value = null;
    emit('updated', updated);
  } catch (e) {
    error.value = e.message || 'Could not reschedule this booking.';
  } finally {
    rescheduleSaving.value = false;
  }
}

const timeRangeLabel = computed(() => {
  if (!props.booking.slot_start) {
    return 'No scheduled time';
  }
  const start = new Date(props.booking.slot_start);
  const end = props.booking.slot_end ? new Date(props.booking.slot_end) : null;
  const dateLabel = start.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
  return end ? `${dateLabel}, ${formatTime(start)} – ${formatTime(end)}` : `${dateLabel}, ${formatTime(start)}`;
});

function label(status) {
  return { confirmed: 'Confirm', completed: 'Mark completed', cancelled: 'Cancel', no_show: 'No-show', rejected: 'Reject' }[status] || status;
}

async function changeStatus(status) {
  error.value = '';
  updating.value = true;
  try {
    const updated = await api.put(`/bookings/${props.booking.id}`, { status });
    emit('updated', updated);
  } catch (e) {
    error.value = e.message || 'Could not update this booking.';
  } finally {
    updating.value = false;
  }
}

async function markPaid() {
  error.value = '';
  markingPaid.value = true;
  try {
    const updated = await api.put(`/bookings/${props.booking.id}/payment`, { payment_status: 'paid_manual' });
    emit('updated', updated);
  } catch (e) {
    error.value = e.message || 'Could not update payment status.';
  } finally {
    markingPaid.value = false;
  }
}
</script>
