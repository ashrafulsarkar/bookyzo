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
      <div class="mb-4 flex flex-wrap items-end gap-4 rounded-lg border border-border bg-muted/30 p-4">
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">From</Label>
          <Popover>
            <PopoverTrigger as-child>
              <Button
                type="button"
                variant="outline"
                class="h-9 w-40 justify-start font-normal"
                :class="!filters.from && 'text-muted-foreground'"
              >
                <CalendarIcon class="h-4 w-4 shrink-0" />
                {{ filters.from ? formatFilterDate(filters.from) : 'Any date' }}
              </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
              <Calendar :model-value="toCalendarDate(filters.from)" @update:model-value="(v) => (filters.from = fromCalendarDate(v))" />
            </PopoverContent>
          </Popover>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">To</Label>
          <Popover>
            <PopoverTrigger as-child>
              <Button
                type="button"
                variant="outline"
                class="h-9 w-40 justify-start font-normal"
                :class="!filters.to && 'text-muted-foreground'"
              >
                <CalendarIcon class="h-4 w-4 shrink-0" />
                {{ filters.to ? formatFilterDate(filters.to) : 'Any date' }}
              </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
              <Calendar :model-value="toCalendarDate(filters.to)" @update:model-value="(v) => (filters.to = fromCalendarDate(v))" />
            </PopoverContent>
          </Popover>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">Bookable item</Label>
          <Select v-model="filters.bookable_item_id">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">All</SelectItem>
              <SelectItem v-for="item in items" :key="item.id" :value="String(item.id)">{{ item.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">Status</Label>
          <Select v-model="filters.status">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">All</SelectItem>
              <SelectItem v-for="status in statuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <Button
          variant="ghost"
          size="sm"
          class="ml-auto text-muted-foreground hover:text-foreground"
          :disabled="!hasActiveFilters"
          @click="clearFilters"
        >
          <X class="h-4 w-4" />
          Clear filters
        </Button>
      </div>

      <ByzoLoading v-if="loading" />

      <Card v-else-if="bookings.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        No bookings match these filters.
      </Card>

      <Card v-else class="overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Customer</TableHead>
              <TableHead>Bookable item</TableHead>
              <TableHead>Party</TableHead>
              <TableHead>When</TableHead>
              <TableHead>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="booking in paginatedBookings"
              :key="booking.id"
              class="cursor-pointer"
              @click="activeBooking = booking"
            >
              <TableCell>
                <span class="block font-medium">{{ booking.customer_name }}</span>
                <span class="block text-xs text-muted-foreground">{{ booking.customer_email }}</span>
              </TableCell>
              <TableCell class="text-muted-foreground">{{ itemName(booking.bookable_item_id) }}</TableCell>
              <TableCell class="text-muted-foreground">{{ booking.party_size }}</TableCell>
              <TableCell class="text-muted-foreground">{{ whenLabel(booking) }}</TableCell>
              <TableCell><ByzoBadge :status="booking.status">{{ booking.status.replace('_', ' ') }}</ByzoBadge></TableCell>
            </TableRow>
          </TableBody>
        </Table>

        <ByzoPagination v-model:page="currentPage" :total-pages="totalPages" :total="totalCount" :page-size="pageSize" />
      </Card>
    </div>

    <!-- Same detail popup the Calendar screen opens on a booking click
         (ByzoCalendarWeek/Month/Agenda) — reused as-is so a booking looks
         and behaves identically whichever screen it's opened from. -->
    <BookingDetailModal
      v-if="activeBooking"
      :booking="activeBooking"
      @close="activeBooking = null"
      @updated="onBookingUpdated"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Calendar } from '@/components/ui/calendar';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import ByzoPagination from '@/components/base/ByzoPagination.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import BookingDetailModal from '../calendar/BookingDetailModal.vue';
import { Calendar as CalendarIcon, X } from 'lucide-vue-next';
import { parseDate } from '@internationalized/date';
import { api } from '@/api/client';
import { useClientPagination } from '@/composables/useClientPagination';

const statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'rejected'];

const items = ref([]);
const bookings = ref([]);
const loading = ref(true);
const activeBooking = ref(null);

const filters = reactive({
  from: '',
  to: '',
  bookable_item_id: '__all__',
  status: '__all__',
});

const { page: currentPage, total: totalCount, totalPages, pageItems: paginatedBookings, pageSize, reset: resetPage } =
  useClientPagination(bookings, 10);

async function loadItems() {
  try {
    items.value = await api.get('/bookable-items?per_page=100');
  } catch (e) {
    // Non-fatal — the bookable-item filter just stays empty.
  }
}

async function loadBookings() {
  loading.value = true;
  try {
    const params = new URLSearchParams({ per_page: '100' });
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.bookable_item_id !== '__all__') params.set('bookable_item_id', filters.bookable_item_id);
    if (filters.status !== '__all__') params.set('status', filters.status);
    bookings.value = await api.get(`/bookings?${params.toString()}`);
  } catch (e) {
    toast.error(e.message || 'Could not load bookings.');
  } finally {
    loading.value = false;
  }
}

const hasActiveFilters = computed(() =>
  Boolean(filters.from || filters.to || filters.bookable_item_id !== '__all__' || filters.status !== '__all__')
);

function clearFilters() {
  filters.from = '';
  filters.to = '';
  filters.bookable_item_id = '__all__';
  filters.status = '__all__';
}

// The From/To filters swapped a native `<input type="date">` — its
// unstyled, browser-drawn "dd-----yyyy" placeholder looked broken next to
// the rest of the app — for a Popover + Calendar picker. `filters.from/to`
// stay plain 'YYYY-MM-DD' strings (what the REST API and the CSV export
// URL both expect); these two just bridge that string to/from the
// Calendar's own @internationalized/date value.
function toCalendarDate(value) {
  return value ? parseDate(value) : undefined;
}

function fromCalendarDate(value) {
  return value ? value.toString() : '';
}

function formatFilterDate(value) {
  return new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function itemName(id) {
  return items.value.find((i) => i.id === id)?.name || '—';
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

// Mirrors BookingCalendar.vue's own handler — the modal is the same
// component either way, so it emits the same shape of event.
function onBookingUpdated(updated) {
  bookings.value = bookings.value.map((b) => (b.id === updated.id ? updated : b));
  activeBooking.value = updated;
  if (['completed', 'cancelled', 'no_show', 'rejected'].includes(updated.status)) {
    activeBooking.value = null;
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
  if (filters.bookable_item_id !== '__all__') params.set('bookable_item_id', filters.bookable_item_id);
  if (filters.status !== '__all__') params.set('status', filters.status);
  return `${base}?${params.toString()}`;
});

let debounceTimer = null;
watch(filters, () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => { resetPage(); loadBookings(); }, 250);
});

onMounted(async () => {
  await loadItems();
  await loadBookings();
});
</script>
