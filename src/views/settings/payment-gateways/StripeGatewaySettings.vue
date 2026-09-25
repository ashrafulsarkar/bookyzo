<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-col gap-1.5">
      <Label for="stripe-pk">{{ __('Publishable key', 'bookyzo') }}</Label>
      <Input id="stripe-pk" :model-value="modelValue.publishable_key" @update:model-value="update('publishable_key', $event)" type="text" :placeholder="__('pk_test_...', 'bookyzo')" class="font-mono" />
    </div>

    <div class="flex flex-col gap-1.5">
      <Label for="stripe-sk">{{ __('Secret key', 'bookyzo') }}</Label>
      <Input id="stripe-sk" :model-value="modelValue.secret_key" @update:model-value="update('secret_key', $event)" type="password" :placeholder="__('sk_test_...', 'bookyzo')" class="font-mono" />
    </div>

    <div class="flex flex-col gap-1.5">
      <Label for="stripe-wh">{{ __('Webhook signing secret', 'bookyzo') }}</Label>
      <Input id="stripe-wh" :model-value="modelValue.webhook_secret" @update:model-value="update('webhook_secret', $event)" type="password" :placeholder="__('whsec_...', 'bookyzo')" class="font-mono" />
      <p class="text-xs text-muted-foreground">
        {{ __('Add a webhook endpoint in your Stripe dashboard pointing to', 'bookyzo') }}
        <code class="rounded bg-muted px-1 py-0.5">{{ webhookUrl }}</code>
        {{ __('listening for the', 'bookyzo') }} <code class="rounded bg-muted px-1 py-0.5">payment_intent.succeeded</code> {{ __('event, then paste its signing secret here.', 'bookyzo') }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
  modelValue: { type: Object, required: true },
});
const emit = defineEmits(['update:modelValue']);

const webhookUrl = computed(() => `${window.location.origin}/wp-json/bookyzo/v1/stripe-webhook`);

function update(key, value) {
  emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>
