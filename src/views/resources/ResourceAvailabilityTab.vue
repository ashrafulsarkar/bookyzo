<template>
  <Card class="max-w-xl">
    <CardHeader>
      <CardTitle>Weekly availability</CardTitle>
      <CardDescription>When this resource can be booked, every week.</CardDescription>
    </CardHeader>
    <CardContent>
      <div v-if="loading" class="py-8 text-center text-sm text-muted-foreground">Loading…</div>
      <ByzoWeeklyScheduleGrid v-else v-model="schedules" />
      <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-end">
      <Button :disabled="loading || saving" @click="save">{{ saving ? 'Saving…' : 'Save availability' }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import ByzoWeeklyScheduleGrid from '@/components/base/ByzoWeeklyScheduleGrid.vue';
import { api } from '@/api/client';

const props = defineProps({
  resourceId: { type: [String, Number], required: true },
});

const schedules = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const availability = await api.get(`/resources/${props.resourceId}/availability`);
    schedules.value = availability.schedules.map((s) => ({
      weekday: s.weekday,
      start_time: s.start_time.slice(0, 5),
      end_time: s.end_time.slice(0, 5),
      shift_group: s.shift_group,
    }));
  } catch (e) {
    error.value = e.message || 'Could not load availability.';
  } finally {
    loading.value = false;
  }
}

async function save() {
  error.value = '';
  saving.value = true;
  try {
    await api.put(`/resources/${props.resourceId}/availability`, { schedules: schedules.value });
  } catch (e) {
    error.value = e.message || 'Could not save availability.';
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
