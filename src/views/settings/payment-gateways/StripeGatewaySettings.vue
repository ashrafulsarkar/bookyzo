<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-col gap-1.5">
      <Label for="stripe-pk">Publishable key</Label>
      <Input id="stripe-pk" :model-value="modelValue.publishable_key" @update:model-value="update('publishable_key', $event)" type="text" placeholder="pk_test_..." class="font-mono" />
    </div>

    <div class="flex flex-col gap-1.5">
      <Label for="stripe-sk">Secret key</Label>
      <Input id="stripe-sk" :model-value="modelValue.secret_key" @update:model-value="update('secret_key', $event)" type="password" placeholder="sk_test_..." class="font-mono" />
    </div>

    <div class="flex flex-col gap-1.5">
      <Label for="stripe-wh">Webhook signing secret</Label>
      <Input id="stripe-wh" :model-value="modelValue.webhook_secret" @update:model-value="update('webhook_secret', $event)" type="password" placeholder="whsec_..." class="font-mono" />
      <p class="text-xs text-muted-foreground">
        Add a webhook endpoint in your Stripe dashboard pointing to
        <code class="rounded bg-muted px-1 py-0.5">{{ webhookUrl }}</code>
        listening for the <code class="rounded bg-muted px-1 py-0.5">payment_intent.succeeded</code> event, then paste its signing secret here.
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
