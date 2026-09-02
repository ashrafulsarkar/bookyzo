<template>
  <div>
    <h2>Your details</h2>

    <div class="field">
      <label for="bw-name">Name</label>
      <input id="bw-name" v-model="form.name" type="text" autocomplete="name" />
    </div>

    <div class="field">
      <label for="bw-email">Email</label>
      <input id="bw-email" v-model="form.email" type="email" autocomplete="email" />
    </div>

    <div class="field">
      <label for="bw-phone">Phone (optional)</label>
      <input id="bw-phone" v-model="form.phone" type="tel" autocomplete="tel" />
    </div>

    <div class="field">
      <label for="bw-notes">Notes (optional)</label>
      <textarea id="bw-notes" v-model="form.notes" rows="2" />
    </div>

    <!-- Honeypot (BYZO_Public_Guard) — visually hidden, never seen or
         filled by a real visitor, tabindex/autocomplete off so
         keyboard/autofill users can't stumble into it either. Bots that
         blanket-fill every input on a form trip this. -->
    <div class="honeypot" aria-hidden="true">
      <label for="bw-website">Website</label>
      <input id="bw-website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="actions">
      <button type="button" class="btn btn-outline" @click="$emit('back')">Back</button>
      <button type="button" class="btn btn-primary" :disabled="!canSubmit || submitting" @click="$emit('submit', form)">
        {{ submitting ? 'Booking…' : 'Confirm booking' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { reactive, computed } from 'vue';

const props = defineProps({
  submitting: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['submit', 'back']);

const form = reactive({
  name: '',
  email: '',
  phone: '',
  notes: '',
  website: '',
});

const canSubmit = computed(() => form.name.trim() !== '' && /\S+@\S+\.\S+/.test(form.email));
</script>
