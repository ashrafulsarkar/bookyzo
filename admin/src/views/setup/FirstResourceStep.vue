<template>
  <Card>
    <CardHeader>
      <CardTitle>Add your first staff member</CardTitle>
      <CardDescription>You can add photos, descriptions, and availability details later.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium" for="resource_name">Name</label>
        <input
          id="resource_name"
          v-model="form.name"
          type="text"
          class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="duration">Appointment length (minutes)</label>
          <input
            id="duration"
            v-model.number="form.duration_minutes"
            type="number"
            min="5"
            step="5"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="price">Price (optional)</label>
          <input
            id="price"
            v-model="form.price"
            type="number"
            min="0"
            step="0.01"
            placeholder="0.00"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
      </div>

      <p class="text-xs text-muted-foreground">
        Default hours (from your business profile) will be applied automatically — you can fine-tune the weekly
        schedule from the Resources screen after setup.
      </p>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">Back</Button>
      <Button :disabled="saving" @click="save">{{ saving ? 'Creating…' : 'Finish setup' }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { api } from '../../api/client';

const emit = defineEmits(['finish', 'back']);

// "Staff Member 1" default naming matches DETAILED-SPEC.md §A1's
// per-type sensible-default convention.
const form = ref({
  name: 'Staff Member 1',
  duration_minutes: 30,
  price: '',
});
const saving = ref(false);
const error = ref('');

async function save() {
  error.value = '';
  if (!form.value.name.trim()) {
    error.value = 'Please enter a name.';
    return;
  }

  saving.value = true;
  try {
    const resource = await api.post('/resources', {
      name: form.value.name,
      type: 'appointment',
      duration_minutes: form.value.duration_minutes,
      price: form.value.price === '' ? null : form.value.price,
      capacity: 1,
      status: 'active',
    });

    // Apply the business profile's default hours as this resource's
    // starting weekly schedule (every day) — otherwise "Default hours
    // will be applied automatically" above would be an empty promise,
    // since a resource with no schedule generates zero bookable slots.
    // The owner can trim days/hours from the Resources screen after setup.
    const settings = await api.get('/settings');
    const schedules = Array.from({ length: 7 }, (_, weekday) => ({
      weekday,
      start_time: settings.default_start_time,
      end_time: settings.default_end_time,
    }));
    await api.put(`/resources/${resource.id}/availability`, { schedules });

    emit('finish');
  } catch (e) {
    error.value = e.message || 'Could not create this resource.';
  } finally {
    saving.value = false;
  }
}
</script>
