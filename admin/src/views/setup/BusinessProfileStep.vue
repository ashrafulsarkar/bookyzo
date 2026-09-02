<template>
  <Card>
    <CardHeader>
      <CardTitle>Tell us about your business</CardTitle>
      <CardDescription>This shows up on booking confirmations and reminder emails.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium" for="business_name">Business name</label>
        <input
          id="business_name"
          v-model="form.business_name"
          type="text"
          class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      </div>

      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium" for="currency">Currency</label>
        <input
          id="currency"
          v-model="form.currency"
          type="text"
          maxlength="3"
          placeholder="USD"
          class="h-9 w-24 rounded-md border border-input bg-background px-3 text-sm uppercase shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
        <p class="text-xs text-muted-foreground">3-letter code, e.g. USD, BDT, GBP.</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="start_time">Default opening time</label>
          <input
            id="start_time"
            v-model="form.default_start_time"
            type="time"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="end_time">Default closing time</label>
          <input
            id="end_time"
            v-model="form.default_end_time"
            type="time"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">Back</Button>
      <Button :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Continue' }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { api } from '../../api/client';

const emit = defineEmits(['next', 'back']);

const form = ref({
  business_name: '',
  currency: 'USD',
  default_start_time: '09:00',
  default_end_time: '17:00',
});
const saving = ref(false);
const error = ref('');

onMounted(async () => {
  try {
    const settings = await api.get('/settings');
    form.value = {
      business_name: settings.business_name,
      currency: settings.currency,
      default_start_time: settings.default_start_time,
      default_end_time: settings.default_end_time,
    };
  } catch (e) {
    // Leave the form's sane defaults in place if settings fail to load —
    // the owner can still fill them in and save.
  }
});

async function save() {
  error.value = '';
  saving.value = true;
  try {
    await api.put('/settings', form.value);
    emit('next');
  } catch (e) {
    error.value = e.message || 'Could not save your business profile. Please check the fields above.';
  } finally {
    saving.value = false;
  }
}
</script>
