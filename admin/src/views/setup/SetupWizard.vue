<template>
  <div class="byzo-setup mx-auto max-w-xl px-6 py-12">
    <div class="mb-8 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <div
          v-for="(label, index) in stepLabels"
          :key="label"
          class="flex items-center gap-2"
        >
          <div
            :class="[
              'flex h-7 w-7 items-center justify-center rounded-full text-xs font-medium',
              index < currentIndex
                ? 'bg-primary text-primary-foreground'
                : index === currentIndex
                  ? 'border-2 border-primary text-primary'
                  : 'border border-border text-muted-foreground',
            ]"
          >
            {{ index + 1 }}
          </div>
          <div
            v-if="index < stepLabels.length - 1"
            class="h-px w-8"
            :class="index < currentIndex ? 'bg-primary' : 'bg-border'"
          />
        </div>
      </div>
      <button
        type="button"
        class="text-sm text-muted-foreground underline-offset-4 hover:underline"
        @click="skip"
      >
        Skip setup
      </button>
    </div>

    <BookingTypeStep v-if="state.step === 'booking_type'" @next="advanceTo('business_profile')" />
    <BusinessProfileStep
      v-else-if="state.step === 'business_profile'"
      @next="advanceTo('first_resource')"
      @back="advanceTo('booking_type')"
    />
    <FirstResourceStep
      v-else-if="state.step === 'first_resource'"
      @finish="finish"
      @back="advanceTo('business_profile')"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../../api/client';
import BookingTypeStep from './BookingTypeStep.vue';
import BusinessProfileStep from './BusinessProfileStep.vue';
import FirstResourceStep from './FirstResourceStep.vue';

const router = useRouter();

const stepLabels = ['Booking type', 'Business profile', 'First resource'];
const state = ref({ step: 'booking_type', skipped: false, needed: true });

const currentIndex = computed(() => {
  const order = ['booking_type', 'business_profile', 'first_resource', 'done'];
  return order.indexOf(state.value.step);
});

onMounted(async () => {
  try {
    state.value = await api.get('/setup');
    if (!state.value.needed) {
      router.replace({ name: 'dashboard' });
    }
  } catch (error) {
    // Setup state failing to load shouldn't strand the owner on a blank
    // page — fall back to step 1 rather than showing an error screen for
    // what is, worst case, a resumable wizard.
    state.value = { step: 'booking_type', skipped: false, needed: true };
  }
});

async function advanceTo(step) {
  state.value = await api.put('/setup', { step });
}

async function finish() {
  state.value = await api.put('/setup', { step: 'done' });
  router.replace({ name: 'dashboard' });
}

async function skip() {
  await api.put('/setup', { skip: true });
  router.replace({ name: 'dashboard' });
}
</script>
