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
});
const emit = defineEmits(['next', 'back']);

const schedules = ref(
  Array.from({ length: 7 }, (_, weekday) => ({
    weekday,
    start_time: props.defaultStartTime,
    end_time: props.defaultEndTime,
    shift_group: 0,
  }))
);

const occurrence = ref({
  date: '',
  start_time: '19:00',
  end_time: '22:00',
});

const error = ref('');

function continueStep() {
  error.value = '';

  if ('event' === props.type) {
    if (!occurrence.value.date) {
      error.value = 'Please pick a date.';
      return;
    }
    emit('next', { occurrence: { ...occurrence.value } });
    return;
  }

  emit('next', { schedules: schedules.value.map((s) => ({ ...s })) });
}
</script>
