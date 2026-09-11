<template>
  <Dialog :open="true" @update:open="(v) => { if (!v) $emit('close'); }">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <div class="flex items-start justify-between gap-3 pr-6">
          <div class="min-w-0">
            <DialogTitle class="truncate">{{ booking.customer_name }}</DialogTitle>
            <DialogDescription class="mt-0.5 flex items-center gap-1.5">
              <CalendarClock class="h-3.5 w-3.5 shrink-0" />
              <span class="truncate">{{ timeRangeLabel }}</span>
            </DialogDescription>
          </div>
          <ByzoBadge :status="booking.status" class="shrink-0">{{ booking.status.replace('_', ' ') }}</ByzoBadge>
        </div>
      </DialogHeader>

      <div class="flex flex-col gap-3 text-sm">
        <div class="flex flex-col gap-2 rounded-lg border border-border bg-muted/30 p-3">
          <div class="flex items-center gap-2.5">
            <Mail class="h-4 w-4 shrink-0 text-muted-foreground" />
            <span class="truncate">{{ booking.customer_email }}</span>
          </div>
          <div v-if="booking.customer_phone" class="flex items-center gap-2.5">
            <Phone class="h-4 w-4 shrink-0 text-muted-foreground" />
            <span>{{ booking.customer_phone }}</span>
          </div>
          <div v-if="booking.party_size > 1" class="flex items-center gap-2.5">
            <Users class="h-4 w-4 shrink-0 text-muted-foreground" />
            <span>{{ booking.party_size }} people</span>
          </div>
          <div v-if="booking.customer_notes" class="flex items-start gap-2.5">
            <StickyNote class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <span class="text-muted-foreground">{{ booking.customer_notes }}</span>
          </div>
        </div>

        <div v-if="booking.payment_amount !== null" class="flex items-center justify-between gap-2 rounded-lg border border-border p-3">
          <div class="flex items-center gap-2.5">
            <Wallet class="h-4 w-4 shrink-0 text-muted-foreground" />
            <span class="font-medium">{{ formatTotal(booking.payment_amount) }}</span>
            <span
              class="text-xs font-medium capitalize"
              :style="{ color: booking.payment_status === 'unpaid' ? 'var(--byzo-warning)' : 'var(--byzo-success)' }"
            >
              {{ booking.payment_status.replace('_', ' ') }}
            </span>
          </div>
          <Button
            v-if="booking.payment_status === 'unpaid'"
            variant="outline"
            size="sm"
            :disabled="markingPaid"
            @click="markPaid"
          >
            {{ markingPaid ? 'Marking…' : 'Mark as paid' }}
          </Button>
        </div>

        <!-- Basic reschedule (added 2026-09-05, Phase 1.6, DETAILED-SPEC.md
             §1.4/§A4) — only offered for a still-open booking; a
             completed/cancelled/no_show/rejected booking has nothing left
             to move. -->
        <div v-if="canReschedule" class="rounded-lg border border-border p-3">
          <Button v-if="!rescheduling" type="button" variant="outline" size="sm" @click="startReschedule">Reschedule</Button>
          <div v-else class="flex flex-col gap-2">
            <Label for="reschedule-slot" class="text-xs font-medium text-muted-foreground">Move to a different time</Label>
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

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </div>

      <!--
        One primary action (whatever moves the booking forward — Confirm,
        Mark completed) stays a normal button; anything that ends the
        booking without moving it forward (Reject/Cancel/No-show) is
        equally destructive-looking and used far less often, so it's tucked
        into one "More actions" menu instead of three same-weight red
        buttons sitting next to Confirm — that layout made it easy to fire
        the wrong one and wrapped onto its own messy row once Reschedule
        was open (see the two screenshots this was reported against).
      -->
      <DialogFooter class="flex items-center justify-between gap-2 sm:justify-between">
        <Button variant="ghost" @click="$emit('close')">Close</Button>
        <div class="flex items-center gap-2">
          <DropdownMenu v-if="secondaryTransitions.length">
            <DropdownMenuTrigger as-child>
              <Button type="button" variant="outline" :disabled="updating">
                More actions
                <ChevronDown class="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                v-for="transition in secondaryTransitions"
                :key="transition"
                class="text-destructive focus:bg-destructive/10 focus:text-destructive"
                :disabled="updating"
                @select="changeStatus(transition)"
              >
                {{ label(transition) }}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button v-if="primaryTransition" :disabled="updating" @click="changeStatus(primaryTransition)">
            {{ updating ? 'Updating…' : label(primaryTransition) }}
          </Button>
        </div>
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
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { CalendarClock, Mail, Phone, Users, StickyNote, Wallet, ChevronDown } from 'lucide-vue-next';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import { api } from '@/api/client';
import { formatTime } from '@/lib/date';
import { formatTotal } from '@/lib/money';

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

// The one transition that moves a booking forward (Confirm, Mark completed)
// is the prominent button; everything else for this status is a "decline"
// transition and goes in the "More actions" menu instead of sitting next
// to it at equal visual weight (see the footer's comment in <template>).
const primaryTransition = computed(() => allowedTransitions.value.find((t) => !declineTransitions.includes(t)) || null);
const secondaryTransitions = computed(() => allowedTransitions.value.filter((t) => t !== primaryTransition.value));

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
