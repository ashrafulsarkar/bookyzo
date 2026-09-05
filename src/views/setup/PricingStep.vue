<template>
  <Card>
    <CardHeader>
      <CardTitle>Pricing</CardTitle>
      <CardDescription>You can change this anytime from Bookable Items.</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="isPaid" />
        This costs money to book
      </label>

      <div v-if="isPaid" class="flex flex-col gap-1.5">
        <Label for="wizard-price">{{ priceLabel }} ({{ currency }})</Label>
        <Input id="wizard-price" v-model="price" type="number" min="0" step="0.01" placeholder="0.00" class="w-40" />
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">Back</Button>
      <Button @click="continueStep">Continue</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

const props = defineProps({
  type: { type: String, required: true },
  currency: { type: String, default: 'USD' },
});
const emit = defineEmits(['next', 'back']);

// Same "Ticket price" relabel as BookableItemDetailsTab.vue's field
// matrix — Event is the only type with its own price label.
const priceLabel = computed(() => ('event' === props.type ? 'Ticket price' : 'Price'));

// Defaults to Free — an owner opting into charging is an explicit
// action, not assumed (matches "genuinely usable free plan" framing).
const isPaid = ref(false);
const price = ref('');
const error = ref('');

function continueStep() {
  error.value = '';

  if (isPaid.value && (price.value === '' || Number(price.value) <= 0)) {
    error.value = 'Please enter a price greater than 0, or turn off "This costs money to book".';
    return;
  }

  emit('next', { price: isPaid.value ? price.value : null });
}
</script>
