<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ type === 'event' ? __('When is it happening?', 'bookyzo') : __('Weekly availability', 'bookyzo') }}</CardTitle>
      <CardDescription>
        {{ type === 'event' ? __('Events are one-off dated occurrences, not a weekly schedule — add every date it runs.', 'bookyzo') : __('When can this be booked, every week? You can fine-tune this later.', 'bookyzo') }}
      </CardDescription>
    </CardHeader>
    <CardContent>
      <!-- Event has no weekly schedule (DETAILED-SPEC.md §1.3) — a list of
           dated occurrences instead. Existing ones (on edit) carry their
           server id; new ones have id: null until the wizard saves them. -->
      <div v-if="type === 'event'" class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end gap-3">
          <div class="flex flex-col gap-1.5">
            <Label for="wizard-occurrence-date">{{ __('Date', 'bookyzo') }}</Label>
            <Input id="wizard-occurrence-date" v-model="occurrenceDraft.date" type="date" class="h-9 w-44" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="wizard-occurrence-start">{{ __('Time', 'bookyzo') }}</Label>
            <div class="flex items-center gap-2">
              <Input id="wizard-occurrence-start" v-model="occurrenceDraft.start_time" type="time" class="h-9 w-auto" />
              <span class="text-sm text-muted-foreground">{{ __('to', 'bookyzo') }}</span>
              <Input v-model="occurrenceDraft.end_time" type="time" class="h-9 w-auto" />
            </div>
          </div>
          <Button variant="outline" class="h-9" @click="addOccurrence">{{ __('Add date', 'bookyzo') }}</Button>
        </div>

        <div v-if="occurrences.length > 0" class="divide-y divide-border rounded-lg border border-border">
          <div
            v-for="(occurrence, index) in occurrences"
            :key="occurrence.id ?? `new-${occurrence.date}-${occurrence.start_time}`"
            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
          >
            <span>
              {{ formatDate(occurrence.date) }}
              <span class="text-muted-foreground">· {{ occurrence.start_time }}–{{ occurrence.end_time }}</span>
              <span v-if="occurrence.capacity_remaining !== undefined" class="text-muted-foreground">{{ sprintf(__('· %s left', 'bookyzo'), occurrence.capacity_remaining) }}</span>
            </span>
            <Button variant="ghost" size="sm" class="h-7 text-destructive hover:text-destructive" @click="removeOccurrence(index)">{{ __('Remove', 'bookyzo') }}</Button>
          </div>
        </div>
        <p v-else class="text-sm text-muted-foreground">{{ __('No dates yet — add the first one above.', 'bookyzo') }}</p>
      </div>

      <template v-else>
        <!-- Phase 2 step 4 (DETAILED-SPEC.md §A2) — link to the shared
             business-hours default instead of this item's own schedule.
             Not shown during onboarding (`compact`), since business hours
             aren't necessarily configured yet at that point in the flow. -->
        <label v-if="!compact" class="mb-4 flex items-center gap-2 text-sm">
          <Switch v-model="usesBusinessHours" />
          {{ __('Use business hours instead of a custom schedule', 'bookyzo') }}
        </label>

        <div v-if="usesBusinessHours" class="rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
          {{ __('This bookable item will follow your business hours and update automatically whenever they change.', 'bookyzo') }}
          {{ sprintf(__('Manage them under %s.', 'bookyzo'), __('Settings → Business hours', 'bookyzo')) }}
          {{ __('Turn this off to set a custom schedule for just this item instead.', 'bookyzo') }}
        </div>
        <ByzoWeeklyScheduleGrid v-else v-model="schedules" />

        <!-- Date exceptions (a holiday, or one-off hours for a specific
             date). Optional, and hidden during onboarding (`compact`). -->
        <div v-if="!compact" class="mt-6 border-t border-border pt-4">
          <p class="text-sm font-medium">{{ __('Date exceptions', 'bookyzo') }} <span class="font-normal text-muted-foreground">{{ __('(optional)', 'bookyzo') }}</span></p>
          <p class="text-sm text-muted-foreground">{{ __('Block a holiday, or override the hours for one specific date.', 'bookyzo') }}</p>

          <div class="mt-3 flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1.5">
              <Label for="wizard-exception-date">{{ __('Date', 'bookyzo') }}</Label>
              <Input id="wizard-exception-date" v-model="exceptionDraft.date" type="date" class="h-9 w-44" />
            </div>
            <label class="flex h-9 items-center gap-2 text-sm">
              <Switch v-model="exceptionDraft.is_closed" />
              {{ __('Fully closed', 'bookyzo') }}
            </label>
            <div v-if="!exceptionDraft.is_closed" class="flex h-9 items-center gap-2">
              <Input v-model="exceptionDraft.start_time" type="time" class="h-9 w-auto" />
              <span class="text-sm text-muted-foreground">{{ __('to', 'bookyzo') }}</span>
              <Input v-model="exceptionDraft.end_time" type="time" class="h-9 w-auto" />
            </div>
            <Button variant="outline" class="h-9" @click="addException">{{ __('Add exception', 'bookyzo') }}</Button>
          </div>

          <div v-if="exceptions.length > 0" class="mt-3 divide-y divide-border rounded-lg border border-border">
            <div
              v-for="(exception, index) in exceptions"
              :key="exception.id ?? `new-${exception.date}`"
              class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
            >
              <span>
                {{ formatDate(exception.date) }}
                <span class="text-muted-foreground">· {{ exception.is_closed ? __('Closed', 'bookyzo') : `${exception.start_time}–${exception.end_time}` }}</span>
              </span>
              <Button variant="ghost" size="sm" class="h-7 text-destructive hover:text-destructive" @click="removeException(index)">{{ __('Remove', 'bookyzo') }}</Button>
            </div>
          </div>
        </div>
      </template>

      <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</Button>
      <Button @click="continueStep">{{ __('Continue', 'bookyzo') }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { weekdayNames } from '@/lib/date';
import { i18n, locale } from '@/lib/i18n';
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import ByzoWeeklyScheduleGrid from '@/components/base/ByzoWeeklyScheduleGrid.vue';

// Nothing here talks to the server: the step only accumulates. The parent
// wizard (BookableItemWizard.vue / SetupWizard.vue) saves on the Review
// step, and on edit diffs the lists against what it loaded (removed
// existing rows → DELETE, id: null rows → POST).
const props = defineProps({
  type: { type: String, required: true },
  // Business profile's default hours seed every day of the grid (and the
  // exception draft's hours), so the owner trims from a sensible starting
  // point rather than building the week from nothing.
  defaultStartTime: { type: String, default: '09:00' },
  defaultEndTime: { type: String, default: '17:00' },
  // The parent wizard's accumulated data from an earlier pass (or the
  // loaded item, on edit). `schedules` is a sentinel: null/undefined =
  // not visited yet (seed every day from the default hours); an array —
  // even an empty one, "closed every day" — is the owner's last answer
  // and comes back exactly as left. `exceptions` / `occurrences` are
  // arrays when present, otherwise start empty.
  initial: { type: Object, default: null },
  // Onboarding: no exceptions section.
  compact: { type: Boolean, default: false },
});
const emit = defineEmits(['next', 'back']);

const WEEKDAY_NAMES = weekdayNames();

const schedules = ref(
  Array.isArray(props.initial?.schedules)
    ? props.initial.schedules.map((s) => ({ ...s }))
    : Array.from({ length: 7 }, (_, weekday) => ({
        weekday,
        start_time: props.defaultStartTime,
        end_time: props.defaultEndTime,
        shift_group: 0,
      }))
);

const usesBusinessHours = ref(Boolean(props.initial?.uses_business_hours));

const exceptions = ref(Array.isArray(props.initial?.exceptions) ? props.initial.exceptions.map((x) => ({ ...x })) : []);
const exceptionDraft = ref({ date: '', is_closed: true, start_time: props.defaultStartTime, end_time: props.defaultEndTime });

const occurrences = ref(Array.isArray(props.initial?.occurrences) ? props.initial.occurrences.map((o) => ({ ...o })) : []);
const occurrenceDraft = ref({ date: '', start_time: '19:00', end_time: '22:00' });

const error = ref('');

function formatDate(date) {
  const parsed = new Date(`${date}T00:00:00`);
  return Number.isNaN(parsed.getTime())
    ? date
    : parsed.toLocaleDateString(locale, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

// Same start < end rule BYZO_Availability::set_schedules() /
// add_exception() and BYZO_Slot::create_occurrence() enforce server-side —
// 'HH:mm' strings (zero-padded, 24h) compare correctly as plain strings,
// which is also exactly how the server compares them. Caught here so the
// owner sees WHICH row is wrong, instead of a generic server error at the
// Review step's save.
function timeRangeError(start, end) {
  if (!start || !end) {
    return i18n.__('Please enter both a start and an end time.', 'bookyzo');
  }
  return start < end ? '' : i18n.__('End time must be after start time.', 'bookyzo');
}

function addOccurrence() {
  error.value = '';
  const draft = occurrenceDraft.value;
  if (!draft.date) {
    error.value = i18n.__('Please pick a date.', 'bookyzo');
    return false;
  }
  const rangeError = timeRangeError(draft.start_time, draft.end_time);
  if (rangeError) {
    error.value = rangeError;
    return false;
  }
  occurrences.value.push({ id: null, date: draft.date, start_time: draft.start_time, end_time: draft.end_time });
  occurrences.value.sort((a, b) => `${a.date} ${a.start_time}`.localeCompare(`${b.date} ${b.start_time}`));
  draft.date = '';
  return true;
}

function removeOccurrence(index) {
  occurrences.value.splice(index, 1);
}

function addException() {
  error.value = '';
  const draft = exceptionDraft.value;
  if (!draft.date) {
    error.value = i18n.__('Please pick a date for the exception.', 'bookyzo');
    return false;
  }
  if (exceptions.value.some((x) => x.date === draft.date)) {
    error.value = i18n.__('There is already an exception on that date — remove it first to change it.', 'bookyzo');
    return false;
  }
  if (!draft.is_closed) {
    const rangeError = timeRangeError(draft.start_time, draft.end_time);
    if (rangeError) {
      error.value = rangeError;
      return false;
    }
  }
  exceptions.value.push({
    id: null,
    date: draft.date,
    is_closed: draft.is_closed,
    start_time: draft.is_closed ? null : draft.start_time,
    end_time: draft.is_closed ? null : draft.end_time,
  });
  exceptions.value.sort((a, b) => a.date.localeCompare(b.date));
  draft.date = '';
  return true;
}

function removeException(index) {
  exceptions.value.splice(index, 1);
}

function continueStep() {
  error.value = '';

  if ('event' === props.type) {
    // A date typed into the add row but not yet added still counts —
    // don't make the owner click "Add date" and then Continue.
    if (occurrenceDraft.value.date && !addOccurrence()) {
      return;
    }
    if (occurrences.value.length === 0) {
      error.value = i18n.__('Please add at least one date.', 'bookyzo');
      return;
    }
    emit('next', { occurrences: occurrences.value.map((o) => ({ ...o })) });
    return;
  }

  for (const shift of schedules.value) {
    const rangeError = timeRangeError(shift.start_time, shift.end_time);
    if (rangeError) {
      error.value = `${WEEKDAY_NAMES[shift.weekday]}: ${rangeError.charAt(0).toLowerCase()}${rangeError.slice(1)}`;
      return;
    }
  }

  if (!props.compact && exceptionDraft.value.date && !addException()) {
    return;
  }

  emit('next', {
    schedules: schedules.value.map((s) => ({ ...s })),
    exceptions: exceptions.value.map((x) => ({ ...x })),
    uses_business_hours: usesBusinessHours.value,
  });
}
</script>
