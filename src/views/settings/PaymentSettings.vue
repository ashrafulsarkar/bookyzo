<template>
  <div>
    <ByzoPageHeader title="Payments" subtitle="Configure the payment gateways available on your site.">
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

    <div class="px-6 pb-8 flex flex-col gap-6">
      <ByzoLoading v-if="loading" />

      <!-- Each registered gateway is its own self-contained on/off block
           (not a tab to switch between) — FEATURES.md §B6 has Pro running
           several gateways simultaneously, so the UI shouldn't imply
           picking just one. The list itself is driven by whatever
           BYZO_Payment_Gateways::all() actually registers: Free only ever
           registers Stripe, so this renders one block today and gains
           more automatically if something else (Pro) hooks
           `byzo_payment_gateways` later — no disabled/locked entries are
           ever shown for a gateway that isn't registered, see
           bookyzo-dev SKILL.md's Guideline-5 rule. -->
      <template v-else v-for="gateway in gateways" :key="gateway.id">
        <component
          :is="gatewayComponents[gateway.id]"
          v-if="gatewayComponents[gateway.id] && form.gateways[gateway.id]"
          v-model="form.gateways[gateway.id]"
        />
        <Card v-else>
          <CardContent class="pt-6 text-sm text-muted-foreground">
            {{ gateway.label }} settings aren't available in this version.
          </CardContent>
        </Card>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import StripeGatewaySettings from './payment-gateways/StripeGatewaySettings.vue';
import { api } from '@/api/client';

// Maps a registered gateway's `id` to the component that renders its
// settings block. A gateway can be registered (appears in the list)
// without an entry here — the fallback message above covers that rather
// than rendering nothing — but in practice every gateway this plugin
// registers ships its own entry alongside the PHP side that registers it.
const gatewayComponents = {
  stripe: StripeGatewaySettings,
};

const gateways = ref([]);
const form = reactive({ gateways: {} });
const loading = ref(true);
const saving = ref(false);

async function load() {
  try {
    const [gatewayList, settings] = await Promise.all([api.get('/payment-gateways'), api.get('/payment-settings')]);
    gateways.value = gatewayList;
    form.gateways = settings.gateways;
  } catch (e) {
    toast.error(e.message || 'Could not load payment settings.');
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const settings = await api.put('/payment-settings', { gateways: form.gateways });
    form.gateways = settings.gateways;
    toast.success('Payment settings saved.');
  } catch (e) {
    toast.error(e.message || 'Could not save payment settings.');
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
