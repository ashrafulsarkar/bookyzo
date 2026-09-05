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

    <div class="flex flex-col gap-6 px-6 pb-8">
      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>
      <div v-else-if="error" class="text-sm text-destructive">{{ error }}</div>

      <!-- DETAILED-SPEC.md §A8: total bookings count by day/week/month.
           CSV export (also §A8) already lives on the Bookings screen
           (step 5), not duplicated here. -->
      <template v-else>
        <ByzoHeroCard :title="heroTitle" :subtitle="heroSubtitle">
          <template #chips>
            <span class="byzo-hero-chip">{{ summary.today.total }} today</span>
            <span class="byzo-hero-chip">{{ summary.this_week.total }} this week</span>
          </template>
          <template #figure>
            <div class="text-right">
              <div class="text-4xl font-bold tabular-nums leading-none">{{ summary.this_month.total }}</div>
              <div class="text-xs text-white/80">bookings this month</div>
            </div>
          </template>
        </ByzoHeroCard>

        <ByzoStatGrid>
          <ByzoStatCard label="Today" :value="summary.today.total" :hint="statusHint(summary.today)" />
          <ByzoStatCard label="This week" :value="summary.this_week.total" :hint="statusHint(summary.this_week)" />
          <ByzoStatCard label="This month" :value="summary.this_month.total" :hint="statusHint(summary.this_month)" />
        </ByzoStatGrid>

        <Card class="p-5">
          <div class="mb-4 flex items-start justify-between gap-4">
            <div>
              <h3 class="text-sm font-semibold">Bookings, last 14 days</h3>
              <p class="text-xs text-muted-foreground">Every confirmed booking, by day.</p>
            </div>
            <span v-if="!trendLoading && trendTotal" class="text-sm font-medium tabular-nums text-muted-foreground">{{ trendTotal }} total</span>
          </div>
          <div v-if="trendLoading" class="py-10 text-center text-sm text-muted-foreground">Loading…</div>
          <div v-else-if="!trend.length" class="py-10 text-center text-sm text-muted-foreground">No bookings in this period yet.</div>
          <ByzoTrendChart v-else :points="trend" />
        </Card>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoStatGrid from '@/components/base/ByzoStatGrid.vue';
import ByzoStatCard from '@/components/base/ByzoStatCard.vue';
import ByzoTrendChart from '@/components/base/ByzoTrendChart.vue';
import ByzoHeroCard from '@/components/base/ByzoHeroCard.vue';
import { api } from '../api/client';

const router = useRouter();

const loading = ref(true);
const error = ref('');
const summary = ref({
  today: { total: 0 },
  this_week: { total: 0 },
  this_month: { total: 0 },
});
const trend = ref([]);
const trendLoading = ref(true);
const trendTotal = computed(() => trend.value.reduce((sum, p) => sum + p.total, 0));

const heroTitle = computed(() => {
  const pending = summary.value.today.pending || 0;
  if (pending > 0) return `${pending} booking${pending === 1 ? '' : 's'} waiting on you today`;
  return "You're all caught up today";
});
const heroSubtitle = computed(() => {
  const confirmed = summary.value.today.confirmed || 0;
  return confirmed > 0
    ? `${confirmed} confirmed booking${confirmed === 1 ? '' : 's'} coming up today.`
    : 'No confirmed bookings left today.';
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

  try {
    trend.value = await api.get('/reports/trend?days=14');
  } catch (e) {
    // Non-fatal — the stat cards above already loaded; the chart card
    // just stays in its loading state rather than blocking the page.
  } finally {
    trendLoading.value = false;
  }
});
</script>
