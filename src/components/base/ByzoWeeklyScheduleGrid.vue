<template>
  <div class="flex flex-col divide-y divide-border">
    <div v-for="weekday in weekdays" :key="weekday.value" class="flex items-center gap-4 py-3">
      <label class="flex w-32 shrink-0 items-center gap-2 text-sm">
        <input type="checkbox" :checked="isOpen(weekday.value)" @change="toggleDay(weekday.value, $event.target.checked)" />
        {{ weekday.label }}
      </label>

      <template v-if="isOpen(weekday.value)">
        <div v-for="(shift, index) in shiftsFor(weekday.value)" :key="index" class="flex items-center gap-2">
          <input
            type="time"
            :value="shift.start_time"
            class="h-8 rounded-md border border-input bg-background px-2 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            @change="updateShift(weekday.value, index, 'start_time', $event.target.value)"
          />
          <span class="text-sm text-muted-foreground">to</span>
          <input
            type="time"
            :value="shift.end_time"
            class="h-8 rounded-md border border-input bg-background px-2 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            @change="updateShift(weekday.value, index, 'end_time', $event.target.value)"
          />
        </div>
      </template>
      <span v-else class="text-sm text-muted-foreground">Closed</span>
    </div>
  </div>
</template>

<script setup>
// The single most load-bearing custom component in the admin app
// (bookyzo-design skill) — Booking Management and Pro's drag-and-drop
// reschedule both build on the visual language established here.
//
// Accepts N shifts per weekday in its data model from day one (the
// `schedules` array can hold multiple rows per weekday, shift_group
// distinguishes them) even though Free's UI only ever renders/writes
// ONE shift per day — Pro's "+ Add shift" button (ROADMAP.md Phase 2
// step 3) extends this SAME component with an "add another shift"
// affordance per day rather than needing a rewrite, matching the
// "don't gate the data model, gate the UI" principle applied to
// component design (see DETAILED-SPEC.md / bookyzo-dev skill).
import { computed } from 'vue';

const props = defineProps({
  // Array of { weekday: 0-6, start_time: 'HH:mm', end_time: 'HH:mm', shift_group?: number }
  modelValue: { type: Array, required: true },
});
const emit = defineEmits(['update:modelValue']);

const weekdays = [
  { value: 0, label: 'Sunday' },
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
];

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
</script>
