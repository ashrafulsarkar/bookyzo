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

      <!-- 1.0.3: this item's own free-cancellation cut-off. Empty = the
           business-wide one (Settings → General). Only in the item
           wizard; onboarding sets the business-wide value. -->
      <div v-if="showCancellation" class="flex flex-col gap-1.5 border-t border-border pt-4">
        <Label for="wizard-cancellation-hours">{{ __('Free cancellation cut-off', 'bookyzo') }} <span class="font-normal text-muted-foreground">{{ __('(optional)', 'bookyzo') }}</span></Label>
        <div class="flex items-center gap-2">
          <Input id="wizard-cancellation-hours" v-model="cancellationHours" type="number" min="0" max="8760" step="1" class="w-24" />
          <span class="text-sm text-muted-foreground">{{ __('hours before the booking (0 = customers can\'t cancel online)', 'bookyzo') }}</span>
        </div>
        <p class="text-xs text-muted-foreground">{{ __('Customers can cancel or change the time themselves until then; it is shown to them before they book. Leave empty to use your business-wide setting.', 'bookyzo') }}</p>
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
  // The item wizard shows the per-item cancellation cut-off; onboarding doesn't.
  showCancellation: { type: Boolean, default: false },
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
const initialCancellation = props.initial?.cancellation_hours;
const cancellationHours = ref(initialCancellation === null || initialCancellation === undefined ? '' : String(initialCancellation));
const error = ref('');

function continueStep() {
  error.value = '';

  if (isPaid.value && (price.value === '' || Number(price.value) <= 0)) {
    error.value = i18n.__('Please enter a price greater than 0, or turn off "This costs money to book".', 'bookyzo');
    return;
  }

  const cancellationText = String(cancellationHours.value ?? '').trim();
  const cancellation = cancellationText === '' ? null : Number(cancellationText);
  if (props.showCancellation && cancellation !== null && !(Number.isInteger(cancellation) && cancellation >= 0 && cancellation <= 8760)) {
    error.value = i18n.__('The cancellation cut-off must be a whole number of hours from 0 to 8760, or empty.', 'bookyzo');
    return;
  }

  emit('next', props.showCancellation ? { price: isPaid.value ? price.value : null, cancellation_hours: cancellation } : { price: isPaid.value ? price.value : null });
}
</script>
