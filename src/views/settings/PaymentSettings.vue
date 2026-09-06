<template>
  <div>
    <ByzoPageHeader title="Payments" subtitle="Accept card payments online with Stripe.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="1" y="4" width="22" height="16" rx="2" />
          <path d="M1 10h22" />
        </svg>
      </template>
      <template #actions>
        <Button :disabled="loading || saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <ByzoLoading v-if="loading" />

      <Card v-else>
        <CardContent class="flex flex-col gap-4 pt-6">
          <label class="flex items-center gap-2 text-sm font-medium">
            <Switch v-model="form.stripe_enabled" />
            Accept card payments via Stripe
          </label>

          <div class="flex flex-col gap-1.5">
            <Label for="pk">Publishable key</Label>
            <Input id="pk" v-model="form.stripe_publishable_key" type="text" placeholder="pk_test_..." class="font-mono" />
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="sk">Secret key</Label>
            <Input id="sk" v-model="form.stripe_secret_key" type="password" placeholder="sk_test_..." class="font-mono" />
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="wh">Webhook signing secret</Label>
            <Input id="wh" v-model="form.stripe_webhook_secret" type="password" placeholder="whsec_..." class="font-mono" />
            <p class="text-xs text-muted-foreground">
              Add a webhook endpoint in your Stripe dashboard pointing to
              <code class="rounded bg-muted px-1 py-0.5">{{ webhookUrl }}</code>
              listening for the <code class="rounded bg-muted px-1 py-0.5">payment_intent.succeeded</code> event, then paste its signing secret here.
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import { api } from '@/api/client';

const form = ref({
  stripe_enabled: false,
  stripe_publishable_key: '',
  stripe_secret_key: '',
  stripe_webhook_secret: '',
});
const loading = ref(true);
const saving = ref(false);

const webhookUrl = computed(() => `${window.location.origin}/wp-json/bookyzo/v1/stripe-webhook`);

async function load() {
  try {
    form.value = await api.get('/payment-settings');
  } catch (e) {
    toast.error(e.message || 'Could not load payment settings.');
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    form.value = await api.put('/payment-settings', form.value);
    toast.success('Payment settings saved.');
  } catch (e) {
    toast.error(e.message || 'Could not save payment settings.');
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
