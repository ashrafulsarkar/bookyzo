<template>
  <div>
    <ByzoPageHeader title="Bookings" subtitle="Every booking, filterable and exportable.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 11l3 3L22 4" />
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
        </svg>
      </template>
      <template #actions>
        <a :href="exportUrl" class="inline-flex">
          <Button variant="outline">Export CSV</Button>
        </a>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1.5">
          <label class="text-xs font-medium text-muted-foreground" for="f-from">From</label>
          <input
            id="f-from"
            v-model="filters.from"
            type="date"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-xs font-medium text-muted-foreground" for="f-to">To</label>
          <input
            id="f-to"
            v-model="filters.to"
            type="date"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-xs font-medium text-muted-foreground" for="f-resource">Resource</label>
          <select
            id="f-resource"
            v-model="filters.resource_id"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <option value="">All</option>
            <option v-for="resource in resources" :key="resource.id" :value="resource.id">{{ resource.name }}</option>
          </select>
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-xs font-medium text-muted-foreground" for="f-status">Status</label>
          <select
            id="f-status"
            v-model="filters.status"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <option value="">All</option>
            <option v-for="status in statuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</option>
          </select>
        </div>
        <Button variant="ghost" @click="clearFilters">Clear</Button>
      </div>

      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <Card v-else-if="bookings.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        No bookings match these filters.
      </Card>

      <Card v-else class="overflow-hidden">
        <table class="w-full text-sm">
          <thead class="border-b border-border bg-muted/50 text-left text-xs uppercase text-muted-foreground">
            <tr>
              <th class="px-4 py-3 font-medium">Customer</th>
              <th class="px-4 py-3 font-medium">Resource</th>
              <th class="px-4 py-3 font-medium">When</th>
              <th class="px-4 py-3 font-medium">Status</th>
              <th class="px-4 py-3 font-medium"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="booking in bookings" :key="booking.id" class="border-b border-border last:border-0">
              <td class="px-4 py-3">
                <span class="block font-medium">{{ booking.customer_name }}</span>
                <span class="block text-xs text-muted-foreground">{{ booking.customer_email }}</span>
              </td>
              <td class="px-4 py-3 text-muted-foreground">{{ resourceName(booking.resource_id) }}</td>
              <td class="px-4 py-3 text-muted-foreground">{{ whenLabel(booking) }}</td>
              <td class="px-4 py-3"><ByzoBadge :status="booking.status">{{ booking.status.replace('_', ' ') }}</ByzoBadge></td>
              <td class="px-4 py-3 text-right">
                <select
                  v-if="allowedTransitions(booking.status).length > 0"
                  class="h-8 rounded-md border border-input bg-background px-2 text-xs shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  :value="booking.status"
                  @change="onStatusChange(booking, $event.target.value)"
                >
                  <option :value="booking.status" disabled>{{ booking.status.replace('_', ' ') }}</option>
                  <option v-for="next in allowedTransitions(booking.status)" :key="next" :value="next">{{ transitionLabel(next) }}</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </Card>

      <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import { api } from '@/api/client';

// Mirrors BYZO_Booking::TRANSITIONS server-side — see the same note in
// BookingDetailModal.vue (calendar screen); the server remains the real
// enforcement, this only decides which options the dropdown offers.
const TRANSITIONS = {
  pending: ['confirmed', 'cancelled', 'no_show'],
  confirmed: ['completed', 'cancelled', 'no_show'],
  completed: [],
  cancelled: [],
  no_show: [],
};
const statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];

const resources = ref([]);
const bookings = ref([]);
const loading = ref(true);
const error = ref('');

const filters = reactive({
  from: '',
  to: '',
  resource_id: '',
  status: '',
});

async function loadResources() {
  try {
    resources.value = await api.get('/resources?per_page=100');
  } catch (e) {
    // Non-fatal — the resource filter just stays empty.
  }
}

async function loadBookings() {
  loading.value = true;
  error.value = '';
  try {
    const params = new URLSearchParams({ per_page: '100' });
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.resource_id) params.set('resource_id', filters.resource_id);
    if (filters.status) params.set('status', filters.status);
    bookings.value = await api.get(`/bookings?${params.toString()}`);
  } catch (e) {
    error.value = e.message || 'Could not load bookings.';
  } finally {
    loading.value = false;
  }
}

function clearFilters() {
  filters.from = '';
  filters.to = '';
  filters.resource_id = '';
  filters.status = '';
}

function resourceName(id) {
  return resources.value.find((r) => r.id === id)?.name || '—';
}

function whenLabel(booking) {
  if (!booking.slot_start) {
    return '—';
  }
  return new Date(booking.slot_start).toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function allowedTransitions(status) {
  return TRANSITIONS[status] || [];
}

function transitionLabel(status) {
  return { confirmed: 'Confirm', completed: 'Mark completed', cancelled: 'Cancel', no_show: 'No-show' }[status] || status;
}

async function onStatusChange(booking, newStatus) {
  error.value = '';
  try {
    const updated = await api.put(`/bookings/${booking.id}`, { status: newStatus });
    bookings.value = bookings.value.map((b) => (b.id === updated.id ? updated : b));
  } catch (e) {
    error.value = e.message || 'Could not update this booking.';
  }
}

// window.byzoAdmin is injected by BYZO_Admin_Assets (both dev and built
// mode) — admin-post.php actions aren't part of the REST API so this
// nonce can't ride wp.apiFetch's own middleware.
const exportUrl = computed(() => {
  const base = window.byzoAdmin?.exportBookingsUrl;
  const nonce = window.byzoAdmin?.exportNonce;
  if (!base || !nonce) {
    return '#';
  }
  const params = new URLSearchParams({ action: 'byzo_export_bookings', _wpnonce: nonce });
  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  if (filters.resource_id) params.set('resource_id', filters.resource_id);
  if (filters.status) params.set('status', filters.status);
  return `${base}?${params.toString()}`;
});

let debounceTimer = null;
watch(filters, () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadBookings, 250);
});

onMounted(async () => {
  await loadResources();
  await loadBookings();
});
</script>
