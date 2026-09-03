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
          <Label class="text-xs text-muted-foreground" for="f-from">From</Label>
          <Input id="f-from" v-model="filters.from" type="date" class="h-9" />
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs text-muted-foreground" for="f-to">To</Label>
          <Input id="f-to" v-model="filters.to" type="date" class="h-9" />
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs text-muted-foreground">Resource</Label>
          <Select v-model="filters.resource_id">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">All</SelectItem>
              <SelectItem v-for="resource in resources" :key="resource.id" :value="String(resource.id)">{{ resource.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs text-muted-foreground">Status</Label>
          <Select v-model="filters.status">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">All</SelectItem>
              <SelectItem v-for="status in statuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <Button variant="ghost" @click="clearFilters">Clear</Button>
      </div>

      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <Card v-else-if="bookings.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        No bookings match these filters.
      </Card>

      <Card v-else class="overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Customer</TableHead>
              <TableHead>Resource</TableHead>
              <TableHead>Party</TableHead>
              <TableHead>When</TableHead>
              <TableHead>Status</TableHead>
              <TableHead></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="booking in bookings" :key="booking.id">
              <TableCell>
                <span class="block font-medium">{{ booking.customer_name }}</span>
                <span class="block text-xs text-muted-foreground">{{ booking.customer_email }}</span>
              </TableCell>
              <TableCell class="text-muted-foreground">{{ resourceName(booking.resource_id) }}</TableCell>
              <TableCell class="text-muted-foreground">{{ booking.party_size }}</TableCell>
              <TableCell class="text-muted-foreground">{{ whenLabel(booking) }}</TableCell>
              <TableCell><ByzoBadge :status="booking.status">{{ booking.status.replace('_', ' ') }}</ByzoBadge></TableCell>
              <TableCell class="text-right">
                <Select
                  v-if="allowedTransitions(booking.status).length > 0"
                  :model-value="booking.status"
                  @update:model-value="(v) => onStatusChange(booking, v)"
                >
                  <SelectTrigger class="h-8 w-36 text-xs"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem :value="booking.status" disabled>{{ booking.status.replace('_', ' ') }}</SelectItem>
                    <SelectItem v-for="next in allowedTransitions(booking.status)" :key="next" :value="next">{{ transitionLabel(next) }}</SelectItem>
                  </SelectContent>
                </Select>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
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

const filters = reactive({
  from: '',
  to: '',
  resource_id: '__all__',
  status: '__all__',
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
  try {
    const params = new URLSearchParams({ per_page: '100' });
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.resource_id !== '__all__') params.set('resource_id', filters.resource_id);
    if (filters.status !== '__all__') params.set('status', filters.status);
    bookings.value = await api.get(`/bookings?${params.toString()}`);
  } catch (e) {
    toast.error(e.message || 'Could not load bookings.');
  } finally {
    loading.value = false;
  }
}

function clearFilters() {
  filters.from = '';
  filters.to = '';
  filters.resource_id = '__all__';
  filters.status = '__all__';
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
  try {
    const updated = await api.put(`/bookings/${booking.id}`, { status: newStatus });
    bookings.value = bookings.value.map((b) => (b.id === updated.id ? updated : b));
    toast.success(`Booking marked ${newStatus.replace('_', ' ')}.`);
  } catch (e) {
    toast.error(e.message || 'Could not update this booking.');
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
  if (filters.resource_id !== '__all__') params.set('resource_id', filters.resource_id);
  if (filters.status !== '__all__') params.set('status', filters.status);
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
