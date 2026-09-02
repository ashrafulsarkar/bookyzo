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
      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <Card v-else class="max-w-xl">
        <CardContent class="flex flex-col gap-4 pt-6">
          <label class="flex items-center gap-2 text-sm font-medium">
            <input type="checkbox" v-model="form.stripe_enabled" />
            Accept card payments via Stripe
          </label>

          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="pk">Publishable key</label>
            <input
              id="pk"
              v-model="form.stripe_publishable_key"
              type="text"
              placeholder="pk_test_..."
              class="h-9 rounded-md border border-input bg-background px-3 font-mono text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>

          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="sk">Secret key</label>
            <input
              id="sk"
              v-model="form.stripe_secret_key"
              type="password"
              placeholder="sk_test_..."
              class="h-9 rounded-md border border-input bg-background px-3 font-mono text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>

          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="wh">Webhook signing secret</label>
            <input
              id="wh"
              v-model="form.stripe_webhook_secret"
              type="password"
              placeholder="whsec_..."
              class="h-9 rounded-md border border-input bg-background px-3 font-mono text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            <p class="text-xs text-muted-foreground">
              Add a webhook endpoint in your Stripe dashboard pointing to
              <code class="rounded bg-muted px-1 py-0.5">{{ webhookUrl }}</code>
              listening for the <code class="rounded bg-muted px-1 py-0.5">payment_intent.succeeded</code> event, then paste its signing secret here.
            </p>
          </div>

          <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
          <p v-if="saved" class="text-sm" style="color: var(--byzo-success)">Saved.</p>
        </CardContent>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import { api } from '@/api/client';

const form = ref({
  stripe_enabled: false,
  stripe_publishable_key: '',
  stripe_secret_key: '',
  stripe_webhook_secret: '',
});
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const saved = ref(false);

const webhookUrl = computed(() => `${window.location.origin}/wp-json/bookyzo/v1/stripe-webhook`);

async function load() {
  try {
    form.value = await api.get('/payment-settings');
  } catch (e) {
    error.value = e.message || 'Could not load payment settings.';
  } finally {
    loading.value = false;
  }
}

async function save() {
  error.value = '';
  saved.value = false;
  saving.value = true;
  try {
    form.value = await api.put('/payment-settings', form.value);
    saved.value = true;
  } catch (e) {
    error.value = e.message || 'Could not save payment settings.';
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
