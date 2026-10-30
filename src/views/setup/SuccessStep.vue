<template>
  <Card>
    <CardContent class="flex flex-col items-center gap-6 px-6 pb-8 pt-10 text-center sm:px-10">
      <div class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--byzo-success-bg)] text-[var(--byzo-success)]">
        <CircleCheck class="h-8 w-8" />
      </div>

      <div class="flex flex-col gap-2">
        <h2 class="text-xl font-semibold text-foreground">{{ __('Your booking is ready!', 'bookyzo') }}</h2>
        <p class="text-sm text-muted-foreground">{{ __('You can start taking bookings as soon as you add the widget to a page.', 'bookyzo') }}</p>
      </div>

      <!-- The shortcode is the one thing the owner has to do next, so it's
           shown and copyable here rather than hidden behind a button label. -->
      <div class="flex w-full flex-col gap-2 text-left">
        <span class="text-sm font-medium text-foreground">{{ __('Paste this on any page or post', 'bookyzo') }}</span>
        <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 p-2 pl-3">
          <!-- bg-transparent/p-0: wp-admin's own `code` style adds a grey
               box, which doubled up inside this one. -->
          <code class="min-w-0 flex-1 truncate bg-transparent p-0 font-mono text-xs text-foreground" :title="shortcode">{{ shortcode }}</code>
          <Button variant="outline" size="sm" class="shrink-0" @click="copyShortcode">
            <Check v-if="copied" class="mr-1 h-3.5 w-3.5" />
            <Copy v-else class="mr-1 h-3.5 w-3.5" />
            {{ copied ? __('Copied!', 'bookyzo') : __('Copy', 'bookyzo') }}
          </Button>
        </div>
      </div>

      <div class="flex w-full flex-col gap-2 sm:flex-row-reverse">
        <Button class="sm:flex-1" @click="$emit('go-dashboard')">{{ __('Go to Dashboard', 'bookyzo') }}</Button>
        <Button variant="outline" class="sm:flex-1" @click="$emit('go-manage')">{{ __('Edit this bookable item', 'bookyzo') }}</Button>
      </div>
      <button type="button" class="text-sm font-medium text-primary underline-offset-4 hover:underline" @click="$emit('add-another')">
        {{ __('+ Add another bookable item', 'bookyzo') }}
      </button>
    </CardContent>
  </Card>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Check, CircleCheck, Copy } from 'lucide-vue-next';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

const props = defineProps({
  bookableItemId: { type: [String, Number], required: true },
});
defineEmits(['go-dashboard', 'go-manage', 'add-another']);

const copied = ref(false);

// Same [bookyzo_booking bookable_item_id="X"] shortcode BookableItemsList.vue
// surfaces per-item (DETAILED-SPEC.md §A5) — the wizard's own shortcut to it.
const shortcode = computed(() => `[bookyzo_booking bookable_item_id="${props.bookableItemId}"]`);

async function copyShortcode() {
  try {
    await navigator.clipboard.writeText(shortcode.value);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch (e) {
    // Clipboard write can fail in an insecure context or without
    // permission — the shortcode is on screen to select by hand, so no
    // error state is needed for a convenience action like this one.
  }
}
</script>
