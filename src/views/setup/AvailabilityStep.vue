<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ type === 'event' ? 'When is it happening?' : 'Weekly availability' }}</CardTitle>
      <CardDescription>
        {{ type === 'event' ? 'Events are one-off dated occurrences, not a weekly schedule (you can add more dates later).' : 'When can this be booked, every week? You can fine-tune this later.' }}
      </CardDescription>
    </CardHeader>
    <CardContent>
      <!-- Event has no weekly schedule (DETAILED-SPEC.md §1.3) — one
           occurrence entry here instead, matching the Dates tab it lands
           on after the wizard (BookableItemDatesTab.vue). -->
      <div v-if="type === 'event'" class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="wizard-occurrence-date">Date</Label>
          <Input id="wizard-occurrence-date" v-model="occurrence.date" type="date" class="h-9 w-48" />
        </div>
        <div class="flex items-center gap-2">
          <Input v-model="occurrence.start_time" type="time" class="h-8 w-auto" />
          <span class="text-sm text-muted-foreground">to</span>
          <Input v-model="occurrence.end_time" type="time" class="h-8 w-auto" />
        </div>
      </div>
      <ByzoWeeklyScheduleGrid v-else v-model="schedules" />

      <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">Back</Button>
      <Button @click="continueStep">Continue</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ByzoWeeklyScheduleGrid from '@/components/base/ByzoWeeklyScheduleGrid.vue';

const props = defineProps({
  type: { type: String, required: true },
  // Business profile's default hours (from the earlier wizard step) seed
  // every day of the grid, matching the old FirstBookableItemStep's
  // "default hours will be applied automatically" behavior — the owner
  // trims days/hours here instead of them being silently applied unseen.
  defaultStartTime: { type: String, default: '09:00' },
  defaultEndTime: { type: String, default: '17:00' },
  // The parent wizard's accumulated data from an earlier pass, so Back
  // from Pricing shows the schedule/date the owner already set. Its
  // `schedules` is a sentinel: null/undefined = this step hasn't been
  // visited yet (seed every day from the default hours); an array — even
  // an empty one, "closed every day" — is the owner's last answer and
  // comes back exactly as left.
  initial: { type: Object, default: null },
});
const emit = defineEmits(['next', 'back']);

const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

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

const occurrence = ref({
  date: props.initial?.occurrence?.date ?? '',
  start_time: props.initial?.occurrence?.start_time ?? '19:00',
  end_time: props.initial?.occurrence?.end_time ?? '22:00',
});

const error = ref('');

// Same start < end rule BYZO_Availability::set_schedules() and
// BYZO_Slot::create_occurrence() enforce server-side — 'HH:mm' strings
// (zero-padded, 24h) compare correctly as plain strings, which is also
// exactly how the server compares them. Caught here so the owner sees
// WHICH day is wrong, instead of a generic server error at the Review
// step's Create (and, for Event, after the item itself already exists).
function timeRangeError(start, end) {
  if (!start || !end) {
    return 'Please enter both a start and an end time.';
  }
  return start < end ? '' : 'End time must be after start time.';
}

function continueStep() {
  error.value = '';

  if ('event' === props.type) {
    if (!occurrence.value.date) {
      error.value = 'Please pick a date.';
      return;
    }
    const rangeError = timeRangeError(occurrence.value.start_time, occurrence.value.end_time);
    if (rangeError) {
      error.value = rangeError;
      return;
    }
    emit('next', { occurrence: { ...occurrence.value } });
    return;
  }

  for (const shift of schedules.value) {
    const rangeError = timeRangeError(shift.start_time, shift.end_time);
    if (rangeError) {
      error.value = `${WEEKDAY_NAMES[shift.weekday]}: ${rangeError.charAt(0).toLowerCase()}${rangeError.slice(1)}`;
      return;
    }
  }

  emit('next', { schedules: schedules.value.map((s) => ({ ...s })) });
}
</script>
