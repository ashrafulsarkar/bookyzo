<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ __('Confirmation & notifications', 'bookyzo') }}</CardTitle>
      <CardDescription>{{ __('You can change any of these later in Settings.', 'bookyzo') }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="form.auto_confirm" />
        {{ __('Automatically confirm new bookings (skip manual review)', 'bookyzo') }}
      </label>

      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="form.send_confirmation_email" />
        {{ __('Send a confirmation email to the customer', 'bookyzo') }}
      </label>

      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="form.send_admin_notification" />
        {{ __('Email me when a new booking comes in', 'bookyzo') }}
      </label>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</Button>
      <Button @click="$emit('next', { ...form })">{{ __('Continue', 'bookyzo') }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';

// Cancellation policy (byzo_settings.cancellation_hours) deliberately
// NOT asked here yet — that setting, and the guest cancel-link consuming
// it, belongs to Phase 1.5 step 7 (not built as of this step); adding
// just the wizard field ahead of the feature it configures would be a
// setting nobody reads yet.
const props = defineProps({
  // Wizard data from an earlier pass, so Back from Review keeps the
  // toggles where the owner left them (SetupWizard.vue v-if-mounts steps).
  initial: { type: Object, default: null },
});
defineEmits(['next', 'back']);

const form = ref({
  auto_confirm: props.initial?.auto_confirm ?? false,
  send_confirmation_email: props.initial?.send_confirmation_email ?? true,
  send_admin_notification: props.initial?.send_admin_notification ?? true,
});
</script>
