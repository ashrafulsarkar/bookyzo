<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ __('Your booking is ready!', 'bookyzo') }}</CardTitle>
      <CardDescription>{{ __('You can start taking bookings as soon as you add the widget to a page.', 'bookyzo') }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-2">
      <Button class="justify-start" @click="$emit('go-dashboard')">{{ __('View Dashboard', 'bookyzo') }}</Button>
      <Button variant="outline" class="justify-start" @click="$emit('go-manage')">{{ __('Manage Booking', 'bookyzo') }}</Button>
      <Button variant="outline" class="justify-start" @click="copyShortcode">
        {{ copied ? __('Copied!', 'bookyzo') : __('View Booking Form (copy shortcode)', 'bookyzo') }}
      </Button>
      <Button variant="ghost" class="justify-start" @click="$emit('add-another')">{{ __('+ Add Another Booking Type', 'bookyzo') }}</Button>
    </CardContent>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

const props = defineProps({
  bookableItemId: { type: [String, Number], required: true },
});
defineEmits(['go-dashboard', 'go-manage', 'add-another']);

const copied = ref(false);

// Same [bookyzo_booking bookable_item_id="X"] shortcode BookableItemsList.vue
// surfaces per-item (DETAILED-SPEC.md §A5) — the wizard's own shortcut to it.
async function copyShortcode() {
  try {
    await navigator.clipboard.writeText(`[bookyzo_booking bookable_item_id="${props.bookableItemId}"]`);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch (e) {
    // Clipboard write can fail in an insecure context or without
    // permission — the button label just doesn't flip to "Copied!",
    // no error state needed for a convenience action like this one.
  }
}
</script>
