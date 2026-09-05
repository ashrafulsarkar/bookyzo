<template>
  <Card>
    <CardHeader>
      <CardTitle>Confirmation &amp; notifications</CardTitle>
      <CardDescription>You can change any of these later in Settings.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="form.auto_confirm" />
        Automatically confirm new bookings (skip manual review)
      </label>

      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="form.send_confirmation_email" />
        Send a confirmation email to the customer
      </label>

      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="form.send_admin_notification" />
        Email me when a new booking comes in
      </label>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">Back</Button>
      <Button @click="$emit('next', { ...form })">Continue</Button>
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
defineEmits(['next', 'back']);

const form = ref({
  auto_confirm: false,
  send_confirmation_email: true,
  send_admin_notification: true,
});
</script>
