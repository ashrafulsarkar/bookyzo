<template>
  <div>
    <ByzoPageHeader title="Dashboard" subtitle="Your booking activity at a glance.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="9" rx="1" />
          <rect x="14" y="3" width="7" height="5" rx="1" />
          <rect x="14" y="12" width="7" height="9" rx="1" />
          <rect x="3" y="16" width="7" height="5" rx="1" />
        </svg>
      </template>
      <template #actions>
        <Button variant="outline" @click="router.push({ name: 'bookings' })">View all bookings</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>
      <div v-else-if="error" class="text-sm text-destructive">{{ error }}</div>

      <!-- DETAILED-SPEC.md §A8: total bookings count by day/week/month.
           CSV export (also §A8) already lives on the Bookings screen
           (step 5), not duplicated here. -->
      <ByzoStatGrid v-else>
        <ByzoStatCard label="Today" :value="summary.today.total" :hint="statusHint(summary.today)" />
        <ByzoStatCard label="This week" :value="summary.this_week.total" :hint="statusHint(summary.this_week)" />
        <ByzoStatCard label="This month" :value="summary.this_month.total" :hint="statusHint(summary.this_month)" />
      </ByzoStatGrid>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { Button } from '@/components/ui/button';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoStatGrid from '@/components/base/ByzoStatGrid.vue';
import ByzoStatCard from '@/components/base/ByzoStatCard.vue';
import { api } from '../api/client';

const router = useRouter();

const loading = ref(true);
const error = ref('');
const summary = ref({
  today: { total: 0 },
  this_week: { total: 0 },
  this_month: { total: 0 },
});

function statusHint(period) {
  const parts = [];
  if (period.pending) parts.push(`${period.pending} pending`);
  if (period.confirmed) parts.push(`${period.confirmed} confirmed`);
  return parts.length ? parts.join(', ') : 'No pending or confirmed bookings';
}

onMounted(async () => {
  try {
    summary.value = await api.get('/reports/summary');
  } catch (e) {
    error.value = e.message || 'Could not load your booking summary.';
  } finally {
    loading.value = false;
  }
});
</script>
