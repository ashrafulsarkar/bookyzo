<template>
  <Dialog :open="true" @update:open="(v) => { if (!v) $emit('close'); }">
    <!-- Scrolls inside the viewport: an open Reschedule panel plus an
         add-on's section (notes, duplicate…) can outgrow a laptop screen.
         `[&>*]:min-w-0`: DialogContent is a grid, and the header's
         nowrap date line sized its column to the full text — every box in
         the popup ran ~44px past its right edge instead of truncating. -->
    <DialogContent class="max-h-[90vh] max-w-md overflow-y-auto [&>*]:min-w-0">
      <DialogHeader>
        <div class="flex items-start justify-between gap-3 pr-6">
          <div class="min-w-0">
            <DialogTitle class="truncate">{{ booking.customer_name }}</DialogTitle>
            <DialogDescription class="mt-0.5 flex items-center gap-1.5">
              <CalendarClock class="h-3.5 w-3.5 shrink-0" />
              <span class="truncate">{{ timeRangeLabel }}</span>
              <span class="shrink-0 font-mono text-xs">· #{{ booking.id }}</span>
            </DialogDescription>
          </div>
          <ByzoBadge :status="booking.status" class="shrink-0">{{ statusLabel(booking.status) }}</ByzoBadge>
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
            <span>{{ sprintf(/*! translators: %s: number of people. */ _n('%s person', '%s people', booking.party_size, 'bookyzo'), booking.party_size) }}</span>
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
              {{ statusLabel(booking.payment_status) }}
            </span>
          </div>
          <Button
            v-if="booking.payment_status === 'unpaid'"
            variant="outline"
            size="sm"
            :disabled="markingPaid"
            @click="markPaid"
          >
            {{ markingPaid ? __('Marking…', 'bookyzo') : __('Mark as paid', 'bookyzo') }}
          </Button>
        </div>

        <!-- Deposits (Phase 2 step 6, DETAILED-SPEC.md §A7) — deposit_amount
             is always present on the response, null for every booking made
             the way Free itself works; this line is purely data-driven,
             never a Pro-gated UI check. "Mark as paid" above already marks
             the FULL booking paid regardless — there's no separate
             collect-the-balance flow yet (deliberately deferred, same as
             the widget's own service picker). -->
        <p
          v-if="booking.deposit_amount !== null && booking.deposit_amount < booking.payment_amount"
          class="text-xs text-muted-foreground"
        >
          {{ sprintf(/*! translators: 1: deposit amount, 2: remaining balance. */ __('Deposit due online: %1$s · Balance due: %2$s', 'bookyzo'), formatTotal(booking.deposit_amount), formatTotal(booking.payment_amount - booking.deposit_amount)) }}
        </p>

        <!-- Basic reschedule (added 2026-09-05, Phase 1.6, DETAILED-SPEC.md
             §1.4/§A4) — only offered for a still-open booking; a
             completed/cancelled/no_show/rejected booking has nothing left
             to move. -->
        <div v-if="canReschedule" class="rounded-lg border border-border p-3">
          <Button v-if="!rescheduling" type="button" variant="outline" size="sm" @click="startReschedule">{{ __('Reschedule', 'bookyzo') }}</Button>
          <div v-else class="flex flex-col gap-2">
            <Label for="reschedule-date" class="text-xs font-medium text-muted-foreground">{{ __('Move to a different time', 'bookyzo') }}</Label>
            <!-- A day first, then that day's times as chips — the same
                 shape the customer widget uses. One flat dropdown of every
                 open slot ran to 141 entries on the demo site (QA report
                 §4.2). -->
            <p v-if="loadingSlots" class="text-sm text-muted-foreground">{{ __('Loading…', 'bookyzo') }}</p>
            <p v-else-if="slots.length === 0" class="text-sm text-muted-foreground">{{ __('No other available times.', 'bookyzo') }}</p>
            <template v-else>
              <Select v-model="rescheduleDate">
                <SelectTrigger id="reschedule-date" class="h-9"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="day in slotDays" :key="day.key" :value="day.key">{{ day.label }} ({{ day.slots.length }})</SelectItem>
                </SelectContent>
              </Select>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="slot in slotsForDate"
                  :key="slot.id"
                  type="button"
                  class="rounded-md border px-2.5 py-1 text-xs transition-colors"
                  :class="newSlotId === slot.id ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:border-primary hover:text-primary'"
                  @click="newSlotId = slot.id"
                >
                  {{ formatSlotTime(slot) }}
                </button>
              </div>
            </template>
            <div class="flex gap-2">
              <Button type="button" size="sm" :disabled="!newSlotId || rescheduleSaving" @click="confirmReschedule">
                {{ rescheduleSaving ? __('Moving…', 'bookyzo') : __('Move booking', 'bookyzo') }}
              </Button>
              <Button type="button" variant="ghost" size="sm" @click="rescheduling = false">{{ __('Cancel', 'bookyzo') }}</Button>
            </div>
          </div>
        </div>

        <!-- An add-on's own tools for this booking (admin-extensions.js
             extraBookingDetailSection, QA report §4.9) — e.g. staff-only
             notes and tags. Absent unless one is registered. -->
        <component
          :is="extraBookingDetailSection"
          v-if="extraBookingDetailSection"
          :booking="booking"
          @updated="(updated) => emit('updated', updated)"
          @changed="emit('changed')"
        />

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
        <Button variant="ghost" @click="$emit('close')">{{ __('Close', 'bookyzo') }}</Button>
        <div class="flex items-center gap-2">
          <DropdownMenu v-if="secondaryTransitions.length">
            <DropdownMenuTrigger as-child>
              <Button type="button" variant="outline" :disabled="updating">
                {{ __('More actions', 'bookyzo') }}
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
            {{ updating ? __('Updating…', 'bookyzo') : label(primaryTransition) }}
          </Button>
        </div>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<script setup>
import { statusLabel } from '@/lib/status';
import { i18n, locale } from '@/lib/i18n';
import { ref, computed, watch } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { CalendarClock, Mail, Phone, Users, StickyNote, Wallet, ChevronDown } from 'lucide-vue-next';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import { api } from '@/api/client';
import { formatTime, bookingStart, bookingEnd, zoneSuffix } from '@/lib/date';
import { formatTotal } from '@/lib/money';
import { extraBookingDetailSection } from '@/lib/admin-extensions';

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
const emit = defineEmits(['close', 'updated', 'changed']);

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
const rescheduleDate = ref('');

// Open times grouped by day, oldest first — the dropdown picks the day,
// the chips pick the time.
const slotDays = computed(() => {
  const byDay = new Map();
  for (const slot of slots.value) {
    const key = slot.start_datetime.slice(0, 10);
    if (!byDay.has(key)) {
      byDay.set(key, { key, label: new Date(`${key}T12:00:00`).toLocaleDateString(locale, { weekday: 'short', month: 'short', day: 'numeric' }), slots: [] });
    }
    byDay.get(key).slots.push(slot);
  }
  return [...byDay.values()];
});

const slotsForDate = computed(() => slotDays.value.find((day) => day.key === rescheduleDate.value)?.slots || []);

// Switching day drops a time picked on the previous one.
watch(rescheduleDate, () => {
  newSlotId.value = null;
});

function formatSlotTime(slot) {
  return new Date(slot.start_datetime.replace(' ', 'T')).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
}
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
    // Open on the first day that has times, so the chips aren't empty.
    rescheduleDate.value = slotDays.value[0]?.key || '';
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

async function confirmReschedule() {
  error.value = '';
  rescheduleSaving.value = true;
  try {
    const updated = await api.put(`/bookings/${props.booking.id}/reschedule`, { slot_id: newSlotId.value });
    rescheduling.value = false;
    newSlotId.value = null;
    emit('updated', updated);
  } catch (e) {
    error.value = e.message || i18n.__('Could not reschedule this booking.', 'bookyzo');
  } finally {
    rescheduleSaving.value = false;
  }
}

const timeRangeLabel = computed(() => {
  if (!props.booking.slot_start) {
    return i18n.__('No scheduled time', 'bookyzo');
  }
  const start = bookingStart(props.booking);
  const end = bookingEnd(props.booking);
  const dateLabel = start.toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric' });
  const range = end ? `${dateLabel}, ${formatTime(start)} – ${formatTime(end)}` : `${dateLabel}, ${formatTime(start)}`;
  return range + zoneSuffix(props.booking);
});

function label(status) {
  return { confirmed: i18n.__('Confirm', 'bookyzo'), completed: i18n.__('Mark completed', 'bookyzo'), cancelled: i18n.__('Cancel', 'bookyzo'), no_show: i18n.__('No-show', 'bookyzo'), rejected: i18n.__('Reject', 'bookyzo') }[status] || status;
}

async function changeStatus(status) {
  error.value = '';
  updating.value = true;
  try {
    const updated = await api.put(`/bookings/${props.booking.id}`, { status });
    emit('updated', updated);
  } catch (e) {
    error.value = e.message || i18n.__('Could not update this booking.', 'bookyzo');
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
    error.value = e.message || i18n.__('Could not update payment status.', 'bookyzo');
  } finally {
    markingPaid.value = false;
  }
}
</script>
