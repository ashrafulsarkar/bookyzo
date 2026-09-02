<template>
  <div class="byzo-dashboard">
    <h1>{{ greeting }}</h1>
    <p class="byzo-dashboard__status">
      REST connectivity: <strong>{{ status }}</strong>
    </p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../api/client';

const greeting = 'Bookyzo';
const status = ref('checking…');

// Placeholder connectivity check for Phase 0 step 7 — proves the Vue
// shell -> wp.apiFetch -> REST -> PHP round trip works end-to-end.
// Replaced by real dashboard stats (today's bookings, etc.) in Phase 1.
onMounted(async () => {
  try {
    await api.get('/resources?per_page=1');
    status.value = 'connected';
  } catch (error) {
    status.value = `error (${error.code ?? 'unknown'})`;
  }
});
</script>

<style scoped>
.byzo-dashboard {
  padding: 24px;
}
</style>
