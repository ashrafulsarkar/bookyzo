<template>
  <div>
    <ByzoPageHeader
      :title="staffMode ? __('My bookings', 'bookyzo') : __('Bookings', 'bookyzo')"
      :subtitle="staffMode ? __('Bookings for the items you work.', 'bookyzo') : __('Every booking, filterable and exportable.', 'bookyzo')"
    >
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 11l3 3L22 4" />
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
        </svg>
      </template>
      <template #actions>
        <a v-if="!staffMode" :href="exportUrl" class="inline-flex">
          <Button variant="outline">{{ __('Export CSV', 'bookyzo') }}</Button>
        </a>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <div class="mb-4 flex flex-wrap items-end gap-4 rounded-lg border border-border bg-muted/30 p-4">
        <div class="flex flex-col gap-1.5">
          <Label for="bookings-search" class="text-xs font-medium text-muted-foreground">{{ __('Search', 'bookyzo') }}</Label>
          <Input id="bookings-search" v-model="filters.search" type="search" :placeholder="__('Name, email or phone', 'bookyzo')" class="h-9 w-56" />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">{{ __('From', 'bookyzo') }}</Label>
          <Popover>
            <PopoverTrigger as-child>
              <Button
                type="button"
                variant="outline"
                class="h-9 w-40 justify-start font-normal"
                :class="!filters.from && 'text-muted-foreground'"
              >
                <CalendarIcon class="h-4 w-4 shrink-0" />
                {{ filters.from ? formatFilterDate(filters.from) : __('Any date', 'bookyzo') }}
              </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
              <Calendar :model-value="toCalendarDate(filters.from)" @update:model-value="(v) => (filters.from = fromCalendarDate(v))" />
            </PopoverContent>
          </Popover>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">{{ __('To', 'bookyzo') }}</Label>
          <Popover>
            <PopoverTrigger as-child>
              <Button
                type="button"
                variant="outline"
                class="h-9 w-40 justify-start font-normal"
                :class="!filters.to && 'text-muted-foreground'"
              >
                <CalendarIcon class="h-4 w-4 shrink-0" />
                {{ filters.to ? formatFilterDate(filters.to) : __('Any date', 'bookyzo') }}
              </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
              <Calendar :model-value="toCalendarDate(filters.to)" @update:model-value="(v) => (filters.to = fromCalendarDate(v))" />
            </PopoverContent>
          </Popover>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">{{ __('Bookable item', 'bookyzo') }}</Label>
          <Select v-model="filters.bookable_item_id">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{{ __('All', 'bookyzo') }}</SelectItem>
              <SelectItem v-for="item in items" :key="item.id" :value="String(item.id)">{{ item.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div v-if="locations.length" class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">{{ __('Location', 'bookyzo') }}</Label>
          <Select v-model="filters.location_id">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{{ __('All', 'bookyzo') }}</SelectItem>
              <SelectItem v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label class="text-xs font-medium text-muted-foreground">{{ __('Status', 'bookyzo') }}</Label>
          <Select v-model="filters.status">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{{ __('All', 'bookyzo') }}</SelectItem>
              <SelectItem v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</SelectItem>
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
          {{ __('Clear filters', 'bookyzo') }}
        </Button>
      </div>

      <!-- Bulk bar — only when an add-on registered bulk actions
           (admin-extensions.js extraBookingBulkActions) and something is
           selected. -->
      <div
        v-if="bulkActions.length && selectedIds.length"
        class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/30 px-4 py-2.5"
      >
        <span class="mr-2 text-sm font-medium">{{ sprintf(__('%s selected', 'bookyzo'), selectedIds.length) }}</span>
        <Button
          v-for="action in bulkActions"
          :key="action.label"
          size="sm"
          variant="outline"
          :class="action.danger && 'text-destructive hover:text-destructive'"
          :disabled="bulkBusy"
          @click="runBulk(action)"
        >
          {{ action.label }}
        </Button>
        <Button size="sm" variant="ghost" class="ml-auto text-muted-foreground" :disabled="bulkBusy" @click="selectedIds = []">{{ __('Clear selection', 'bookyzo') }}</Button>
      </div>

      <ByzoLoading v-if="loading" />

      <Card v-else-if="bookings.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        {{ __('No bookings match these filters.', 'bookyzo') }}
      </Card>

      <Card v-else class="overflow-hidden">
        <Table class="min-w-[720px]">
          <TableHeader>
            <TableRow>
              <!-- The number staff quote on the phone and type into Check-in's
                   manual field — it used to appear nowhere in the admin. -->
              <TableHead v-if="bulkActions.length" class="w-10">
                <input
                  type="checkbox"
                  class="h-4 w-4 cursor-pointer align-middle"
                  :aria-label="__('Select every booking on this page', 'bookyzo')"
                  :checked="pageAllSelected"
                  @change="togglePage($event.target.checked)"
                />
              </TableHead>
              <TableHead class="w-16">#</TableHead>
              <TableHead>{{ __('Customer', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Bookable item', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Party', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('When', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Status', 'bookyzo') }}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="booking in paginatedBookings"
              :key="booking.id"
              class="cursor-pointer"
              @click="activeBooking = booking"
            >
              <TableCell v-if="bulkActions.length" @click.stop>
                <input
                  type="checkbox"
                  class="h-4 w-4 cursor-pointer align-middle"
                  :aria-label="sprintf(__('Select booking #%s', 'bookyzo'), booking.id)"
                  :checked="selectedIds.includes(booking.id)"
                  @change="toggleOne(booking.id, $event.target.checked)"
                />
              </TableCell>
              <TableCell class="font-mono text-xs text-muted-foreground">{{ booking.id }}</TableCell>
              <TableCell>
                <span class="block font-medium">{{ booking.customer_name }}</span>
                <span class="block text-xs text-muted-foreground">{{ booking.customer_email }}</span>
                <!-- Staff-only tags an add-on may set on a booking; Free
                     itself never writes any, so this is normally absent. -->
                <span v-if="tagList(booking).length" class="mt-1 flex flex-wrap gap-1">
                  <span v-for="tag in tagList(booking)" :key="tag" class="rounded-full border border-border bg-muted/40 px-2 py-px text-[11px] text-muted-foreground">{{ tag }}</span>
                </span>
              </TableCell>
              <TableCell class="text-muted-foreground">{{ itemName(booking.bookable_item_id) }}</TableCell>
              <TableCell class="text-muted-foreground">{{ booking.party_size }}</TableCell>
              <TableCell class="text-muted-foreground">{{ whenLabel(booking) }}</TableCell>
              <TableCell>
                <ByzoBadge :status="booking.status">{{ statusLabel(booking.status) }}</ByzoBadge>
                <!-- Set by a refund add-on when the customer cancels a paid
                     booking (QA report §6.2.7); Free never sets it. -->
                <span v-if="booking.refund_status === 'requested'" class="mt-1 block text-[11px] font-medium text-amber-700">{{ __('Refund requested', 'bookyzo') }}</span>
              </TableCell>
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
      @changed="loadBookings"
    />
  </div>
</template>

<script setup>
import { statusLabel } from '@/lib/status';
import { staffMode } from '@/lib/staff';
import { i18n, locale } from '@/lib/i18n';
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { extraBookingBulkActions as bulkActions, extraLocations as locations } from '@/lib/admin-extensions';

const statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'rejected'];

const items = ref([]);
const bookings = ref([]);
const loading = ref(true);
const activeBooking = ref(null);

const filters = reactive({
  search: '',
  from: '',
  to: '',
  bookable_item_id: '__all__',
  location_id: '__all__',
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

// Selection for an add-on's bulk actions (see the bulk bar). Kept across
// pages, so "select, next page, select more" works; cleared whenever the
// list reloads, since the rows may no longer match the filters.
const selectedIds = ref([]);
const bulkBusy = ref(false);

const pageAllSelected = computed(
  () => paginatedBookings.value.length > 0 && paginatedBookings.value.every((b) => selectedIds.value.includes(b.id))
);

function toggleOne(id, checked) {
  selectedIds.value = checked ? [...new Set([...selectedIds.value, id])] : selectedIds.value.filter((x) => x !== id);
}

function togglePage(checked) {
  const ids = paginatedBookings.value.map((b) => b.id);
  selectedIds.value = checked ? [...new Set([...selectedIds.value, ...ids])] : selectedIds.value.filter((x) => !ids.includes(x));
}

async function runBulk(action) {
  bulkBusy.value = true;
  try {
    const result = await action.run([...selectedIds.value]);
    toast.success(result?.message || i18n.sprintf(i18n.__('%s: done.', 'bookyzo'), action.label));
  } catch (e) {
    toast.error(e.message || i18n.sprintf(i18n.__('Could not apply "%s" to these bookings.', 'bookyzo'), action.label));
  } finally {
    bulkBusy.value = false;
  }
  await loadBookings();
}

function tagList(booking) {
  return String(booking.tags || '')
    .split(',')
    .map((t) => t.trim())
    .filter(Boolean);
}

async function loadBookings() {
  loading.value = true;
  selectedIds.value = [];
  try {
    const params = new URLSearchParams({ per_page: '100' });
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.bookable_item_id !== '__all__') params.set('bookable_item_id', filters.bookable_item_id);
    if (filters.location_id !== '__all__') params.set('location_id', filters.location_id);
    if (filters.status !== '__all__') params.set('status', filters.status);
    if (filters.search.trim()) params.set('search', filters.search.trim());
    bookings.value = await api.get(`/bookings?${params.toString()}`);
  } catch (e) {
    toast.error(e.message || i18n.__('Could not load bookings.', 'bookyzo'));
  } finally {
    loading.value = false;
  }
}

const hasActiveFilters = computed(() =>
  Boolean(filters.from || filters.to || filters.bookable_item_id !== '__all__' || filters.location_id !== '__all__' || filters.status !== '__all__')
);

function clearFilters() {
  filters.from = '';
  filters.to = '';
  filters.bookable_item_id = '__all__';
  filters.location_id = '__all__';
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
  return new Date(`${value}T00:00:00`).toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });
}

function itemName(id) {
  return items.value.find((i) => i.id === id)?.name || '—';
}

function whenLabel(booking) {
  if (!booking.slot_start) {
    return '—';
  }
  return new Date(booking.slot_start).toLocaleString(locale, {
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
