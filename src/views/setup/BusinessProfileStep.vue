<template>
  <Card>
    <CardHeader>
      <CardTitle>Tell us about your business</CardTitle>
      <CardDescription>This shows up on booking confirmations and reminder emails.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <div class="flex flex-col gap-1.5">
        <Label for="business_name">Business name</Label>
        <Input id="business_name" v-model="form.business_name" type="text" />
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="currency">Currency</Label>
        <Input id="currency" v-model="form.currency" type="text" maxlength="3" placeholder="USD" class="w-24 uppercase" />
        <p class="text-xs text-muted-foreground">3-letter code, e.g. USD, BDT, GBP.</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="start_time">Default opening time</Label>
          <Input id="start_time" v-model="form.default_start_time" type="time" />
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="end_time">Default closing time</Label>
          <Input id="end_time" v-model="form.default_end_time" type="time" />
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    // Later steps (AvailabilityStep's default hours, PricingStep's
    // currency label) need these without re-fetching /settings
    // themselves — pass them up alongside the unchanged `next` signal.
    emit('next', { currency: form.value.currency, default_start_time: form.value.default_start_time, default_end_time: form.value.default_end_time });
  } catch (e) {
    error.value = e.message || 'Could not save your business profile. Please check the fields above.';
  } finally {
    saving.value = false;
  }
}
</script>
