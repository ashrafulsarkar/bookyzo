<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ __('Pricing', 'bookyzo') }}</CardTitle>
      <CardDescription>{{ __('You can change this anytime from Bookable Items.', 'bookyzo') }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="isPaid" />
        {{ __('This costs money to book', 'bookyzo') }}
      </label>

      <div v-if="isPaid" class="flex flex-col gap-1.5">
        <Label for="wizard-price">{{ priceLabel }} ({{ currency }})</Label>
        <Input id="wizard-price" v-model="price" type="number" min="0" step="0.01" placeholder="0.00" class="w-40" />
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</Button>
      <Button @click="continueStep">{{ __('Continue', 'bookyzo') }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
import { ref, computed } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

const props = defineProps({
  type: { type: String, required: true },
  currency: { type: String, default: 'USD' },
  // The parent wizard's accumulated data from an earlier pass, so Back
  // from Review keeps the paid toggle and price. `price: null` means both
  // "not visited yet" and "Free", so the toggle only comes back on for a
  // real price — which is exactly the Free-by-default rule below anyway.
  initial: { type: Object, default: null },
});
const emit = defineEmits(['next', 'back']);

// Same "Ticket price" relabel as BookingDetailsStep.vue's edit-mode field
// matrix — Event is the only type with its own price label.
const priceLabel = computed(() => ('event' === props.type ? i18n.__('Ticket price', 'bookyzo') : i18n.__('Price', 'bookyzo')));

// Defaults to Free — an owner opting into charging is an explicit
// action, not assumed (matches "genuinely usable free plan" framing).
const initialPrice = props.initial?.price;
const hasInitialPrice = initialPrice !== null && initialPrice !== undefined && initialPrice !== '';
const isPaid = ref(hasInitialPrice);
const price = ref(hasInitialPrice ? String(initialPrice) : '');
const error = ref('');

function continueStep() {
  error.value = '';

  if (isPaid.value && (price.value === '' || Number(price.value) <= 0)) {
    error.value = i18n.__('Please enter a price greater than 0, or turn off "This costs money to book".', 'bookyzo');
    return;
  }

  emit('next', { price: isPaid.value ? price.value : null });
}
</script>
