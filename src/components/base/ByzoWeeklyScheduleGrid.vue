<template>
  <div class="flex flex-col divide-y divide-border">
    <div v-for="weekday in weekdays" :key="weekday.value" class="flex items-center gap-4 py-3">
      <label class="flex w-32 shrink-0 items-center gap-2 text-sm">
        <Switch :model-value="isOpen(weekday.value)" @update:model-value="(checked) => toggleDay(weekday.value, checked)" />
        {{ weekday.label }}
      </label>

      <template v-if="isOpen(weekday.value)">
        <div class="flex flex-1 flex-wrap items-center gap-x-4 gap-y-2">
          <div v-for="(shift, index) in shiftsFor(weekday.value)" :key="index" class="flex items-center gap-2">
            <Input
              type="time"
              class="h-8 w-auto"
              :model-value="shift.start_time"
              @update:model-value="(value) => updateShift(weekday.value, index, 'start_time', value)"
            />
            <span class="text-sm text-muted-foreground">{{ __('to', 'bookyzo') }}</span>
            <Input
              type="time"
              class="h-8 w-auto"
              :model-value="shift.end_time"
              @update:model-value="(value) => updateShift(weekday.value, index, 'end_time', value)"
            />
            <!-- Only offered once there's a second shift to remove — the
                 day's own toggle switch is how the first/only shift goes
                 away. Free callers can click this same button; the server
                 (BYZO_Availability::set_schedules(), byzo_shift_limit_reached)
                 is what actually enforces max_shifts_per_day(), same
                 "don't hide it, let the real wall answer honestly" pattern
                 the Bookable Items cap already established — see
                 bookyzo-dev SKILL.md. -->
            <button
              v-if="shiftsFor(weekday.value).length > 1"
              type="button"
              class="text-muted-foreground hover:text-destructive"
              :aria-label="sprintf(__('Remove this shift on %s', 'bookyzo'), weekday.label)"
              @click="removeShift(weekday.value, index)"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
            </button>
          </div>

          <button
            type="button"
            class="text-xs font-medium text-primary hover:underline"
            @click="addShift(weekday.value)"
          >
            {{ __('+ Add shift', 'bookyzo') }}
          </button>
        </div>
      </template>
      <span v-else class="text-sm text-muted-foreground">{{ __('Closed', 'bookyzo') }}</span>
    </div>
  </div>
</template>

<script setup>
import { weekdayNames } from '@/lib/date';
// The single most load-bearing custom component in the admin app
// (bookyzo-design skill) — Booking Management and Pro's drag-and-drop
// reschedule both build on the visual language established here.
//
// Accepts N shifts per weekday in its data model from day one (the
// `schedules` array can hold multiple rows per weekday, shift_group
// distinguishes them). The "+ Add shift"/remove-shift controls
// (ROADMAP.md Phase 2 step 3) are always available here, in Free too —
// BYZO_Availability::set_schedules()'s own byzo_shift_limit_reached 403
// is what actually enforces BYZO_Capabilities::max_shifts_per_day()
// (Free = 1, Pro overrides the filter), same "let the real wall answer
// honestly instead of hiding the control" pattern already established
// for the Bookable Items per-type cap — see bookyzo-dev SKILL.md.
import { computed } from 'vue';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';

const props = defineProps({
  // Array of { weekday: 0-6, start_time: 'HH:mm', end_time: 'HH:mm', shift_group?: number }
  modelValue: { type: Array, required: true },
});
const emit = defineEmits(['update:modelValue']);

const weekdays = weekdayNames().map((label, value) => ({ value, label }));

const schedules = computed(() => props.modelValue);

function shiftsFor(weekday) {
  return schedules.value.filter((s) => s.weekday === weekday);
}

function isOpen(weekday) {
  return shiftsFor(weekday).length > 0;
}

function toggleDay(weekday, checked) {
  const next = schedules.value.filter((s) => s.weekday !== weekday);
  if (checked) {
    next.push({ weekday, start_time: '09:00', end_time: '17:00', shift_group: 0 });
  }
  emit('update:modelValue', next.sort((a, b) => a.weekday - b.weekday));
}

function updateShift(weekday, index, field, value) {
  const matchIndexes = schedules.value
    .map((s, i) => (s.weekday === weekday ? i : -1))
    .filter((i) => i !== -1);
  const targetIndex = matchIndexes[index];
  if (targetIndex === undefined) {
    return;
  }
  const next = schedules.value.map((s, i) => (i === targetIndex ? { ...s, [field]: value } : s));
  emit('update:modelValue', next);
}

// Phase 2 step 3 (ROADMAP.md) — Pro's "multiple shifts per day" unlock.
// Free's own capacity check (BYZO_Availability::set_schedules(),
// byzo_shift_limit_reached) is what actually enforces the limit; this
// control is available to every caller of this component the same way
// the Bookable Items "+ Add" button always is. Defaults to a plausible
// second "dinner" service window (FEATURES.md B2's own lunch+dinner
// example) rather than duplicating the day's existing hours.
function addShift(weekday) {
  const nextGroup = shiftsFor(weekday).length;
  const next = [...schedules.value, { weekday, start_time: '18:00', end_time: '21:00', shift_group: nextGroup }];
  emit('update:modelValue', next);
}

function removeShift(weekday, index) {
  const matchIndexes = schedules.value
    .map((s, i) => (s.weekday === weekday ? i : -1))
    .filter((i) => i !== -1);
  const targetIndex = matchIndexes[index];
  if (targetIndex === undefined) {
    return;
  }
  const next = schedules.value.filter((s, i) => i !== targetIndex);
  emit('update:modelValue', next);
}
</script>
