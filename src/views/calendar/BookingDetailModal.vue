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

        <p v-if="error" class="text-destructive">{{ error }}</p>
      </div>
      <DialogFooter class="flex-wrap gap-2">
        <Button variant="outline" @click="$emit('close')">Close</Button>
        <Button
          v-for="transition in allowedTransitions"
          :key="transition"
          :variant="transition === 'cancelled' || transition === 'no_show' ? 'outline' : 'default'"
          :class="(transition === 'cancelled' || transition === 'no_show') && 'text-destructive hover:text-destructive'"
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
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import { api } from '@/api/client';
import { formatTime } from '@/lib/date';

// Mirrors BYZO_Booking::TRANSITIONS server-side (includes/class-byzo-booking.php)
// — the server is the actual source of truth/enforcement, this is just so
// the UI doesn't offer a button that would 400. Kept as a small literal
// map rather than a shared import since PHP/JS can't share a constant
// directly; if the server-side table ever changes, this needs a matching edit.
const TRANSITIONS = {
  pending: ['confirmed', 'cancelled', 'no_show'],
  confirmed: ['completed', 'cancelled', 'no_show'],
  completed: [],
  cancelled: [],
  no_show: [],
};

const props = defineProps({
  booking: { type: Object, required: true },
});
const emit = defineEmits(['close', 'updated']);

const updating = ref(false);
const markingPaid = ref(false);
const error = ref('');

const allowedTransitions = computed(() => TRANSITIONS[props.booking.status] || []);

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
  return { confirmed: 'Confirm', completed: 'Mark completed', cancelled: 'Cancel', no_show: 'No-show' }[status] || status;
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
