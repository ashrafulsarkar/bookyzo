<template>
  <div>
    <ByzoPageHeader :title="__('Dashboard', 'bookyzo')" :subtitle="__('Your booking activity at a glance.', 'bookyzo')">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="9" rx="1" />
          <rect x="14" y="3" width="7" height="5" rx="1" />
          <rect x="14" y="12" width="7" height="9" rx="1" />
          <rect x="3" y="16" width="7" height="5" rx="1" />
        </svg>
      </template>
      <template #actions>
        <Button variant="outline" @click="router.push({ name: 'bookings' })">{{ __('View all bookings', 'bookyzo') }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="flex flex-col gap-6 px-6 pb-8">
      <ByzoLoading v-if="loading" />
      <div v-else-if="error" class="text-sm text-destructive">{{ error }}</div>

      <!-- DETAILED-SPEC.md §A8: total bookings count by day/week/month.
           CSV export (also §A8) already lives on the Bookings screen
           (step 5), not duplicated here. -->
      <template v-else>
        <ByzoStatGrid>
          <ByzoStatCard :label="__('Today', 'bookyzo')" :value="summary.today.total" :hint="statusHint(summary.today)" />
          <ByzoStatCard :label="__('This week', 'bookyzo')" :value="summary.this_week.total" :hint="statusHint(summary.this_week)" />
          <ByzoStatCard :label="__('This month', 'bookyzo')" :value="summary.this_month.total" :hint="statusHint(summary.this_month)" />
          <!-- Revenue (Phase 1.5 step 10, DETAILED-SPEC.md §A8) — one
               summed total for the month, no bookable item/type
               breakdown (that stays Pro, B8). -->
          <ByzoStatCard :label="__('Revenue (this month)', 'bookyzo')" :value="formatRevenue(summary.this_month.revenue)" :hint="__('Paid bookings only', 'bookyzo')" />
        </ByzoStatGrid>

        <Card class="p-5">
          <div class="mb-4 flex items-start justify-between gap-4">
            <div>
              <h3 class="text-sm font-semibold">{{ __('Bookings, last 14 days', 'bookyzo') }}</h3>
              <p class="text-xs text-muted-foreground">{{ __('Every confirmed booking, by day.', 'bookyzo') }}</p>
            </div>
            <span v-if="!trendLoading && trendTotal" class="text-sm font-medium tabular-nums text-muted-foreground">{{ sprintf(__('%s total', 'bookyzo'), trendTotal) }}</span>
          </div>
          <ByzoLoading v-if="trendLoading" size="compact" />
          <div v-else-if="!trendTotal" class="py-10 text-center text-sm text-muted-foreground">{{ __('No bookings in this period yet.', 'bookyzo') }}</div>
          <ByzoTrendChart v-else :points="trend" />
        </Card>

        <!-- Upcoming bookings widget (Phase 1.5 step 10) — a bounded
             GET /bookings call (from=now, status=pending,confirmed,
             per_page=5), not a new endpoint. A summary, not a
             replacement for the full Bookings list/calendar. -->
        <Card class="p-5">
          <div class="mb-4 flex items-center justify-between gap-4">
            <h3 class="text-sm font-semibold">{{ __('Upcoming bookings', 'bookyzo') }}</h3>
            <Button variant="ghost" size="sm" @click="router.push({ name: 'bookings' })">{{ __('View all', 'bookyzo') }}</Button>
          </div>
          <ByzoLoading v-if="upcomingLoading" size="compact" />
          <div v-else-if="!upcoming.length" class="py-10 text-center text-sm text-muted-foreground">{{ __('Nothing pending or confirmed coming up.', 'bookyzo') }}</div>
          <div v-else class="flex flex-col divide-y">
            <div v-for="booking in upcoming" :key="booking.id" class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
              <div class="min-w-0">
                <span class="block truncate font-medium">{{ booking.customer_name }}</span>
                <span class="block truncate text-xs text-muted-foreground">{{ itemName(booking.bookable_item_id) }} · {{ whenLabel(booking) }}</span>
              </div>
              <ByzoBadge :status="booking.status">{{ statusLabel(booking.status) }}</ByzoBadge>
            </div>
          </div>
        </Card>
      </template>
    </div>
  </div>
</template>

<script setup>
import { statusLabel } from '@/lib/status';
import { i18n, locale } from '@/lib/i18n';
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoStatGrid from '@/components/base/ByzoStatGrid.vue';
import ByzoStatCard from '@/components/base/ByzoStatCard.vue';
import ByzoTrendChart from '@/components/base/ByzoTrendChart.vue';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import { api } from '../api/client';
import { formatTotal } from '@/lib/money';

const router = useRouter();

const loading = ref(true);
const error = ref('');
const summary = ref({
  today: { total: 0 },
  this_week: { total: 0 },
  this_month: { total: 0, revenue: 0 },
});
const trend = ref([]);
const trendLoading = ref(true);
const trendTotal = computed(() => trend.value.reduce((sum, p) => sum + p.total, 0));

const bookableItems = ref([]);
const itemsLoading = ref(true);

const upcoming = ref([]);
const upcomingLoading = ref(true);

function statusHint(period) {
  const parts = [];
  if (period.pending) parts.push(i18n.sprintf(i18n.__('%s pending', 'bookyzo'), period.pending));
  if (period.confirmed) parts.push(i18n.sprintf(i18n.__('%s confirmed', 'bookyzo'), period.confirmed));

  if (parts.length) {
    return parts.join(', ');
  }

  // The tile's big number counts bookings of EVERY status, so a period
  // holding only completed/cancelled/no-show ones used to read
  // "1" over "No pending or confirmed bookings" — a caption that flatly
  // contradicts the number above it. Say what those bookings actually
  // are instead, and keep the "nothing at all" wording for a real zero.
  return period.total ? i18n.__('Nothing still open', 'bookyzo') : i18n.__('No bookings', 'bookyzo');
}

function formatRevenue(amount) {
  return formatTotal(amount);
}

function itemName(id) {
  return bookableItems.value.find((i) => i.id === id)?.name || '—';
}

function whenLabel(booking) {
  if (!booking.slot_start) {
    return '—';
  }
  return new Date(booking.slot_start.replace(' ', 'T')).toLocaleString(locale, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

onMounted(async () => {
  try {
    summary.value = await api.get('/reports/summary');
  } catch (e) {
    error.value = e.message || i18n.__('Could not load your booking summary.', 'bookyzo');
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

  try {
    bookableItems.value = await api.get('/bookable-items?status=active&per_page=100');
  } catch (e) {
    // Non-fatal — itemName() below falls back to "—" for the
    // upcoming-bookings widget.
  } finally {
    itemsLoading.value = false;
  }

  try {
    upcoming.value = await api.get('/bookings?from=now&status=pending,confirmed&per_page=5');
  } catch (e) {
    // Non-fatal — the widget just stays empty rather than blocking the
    // rest of an already-loaded dashboard.
  } finally {
    upcomingLoading.value = false;
  }
});
</script>
