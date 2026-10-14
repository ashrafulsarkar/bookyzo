<template>
  <div>
    <ByzoPageHeader :title="__('Bookable Items', 'bookyzo')" :subtitle="__('Your staff, tables, or bookable items.', 'bookyzo')">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
      </template>
      <template #actions>
        <Button @click="openAdd">{{ __('+ Add bookable item', 'bookyzo') }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <div v-if="!loading && items.length > 0" class="mb-4 flex flex-col gap-1.5">
        <Label class="text-xs text-muted-foreground">{{ __('Type', 'bookyzo') }}</Label>
        <Select v-model="typeFilter">
          <SelectTrigger class="h-10 w-40"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">{{ __('All', 'bookyzo') }}</SelectItem>
            <SelectItem v-for="type in TYPES" :key="type" :value="type">{{ TYPE_LABELS[type] }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <ByzoLoading v-if="loading" />

      <Card v-else-if="items.length === 0" class="flex flex-col items-center gap-3 py-16 text-center">
        <p class="font-medium">{{ __('No bookable items yet', 'bookyzo') }}</p>
        <p class="max-w-sm text-sm text-muted-foreground">
          {{ __('Add your first staff member, table, or bookable item to start accepting bookings.', 'bookyzo') }}
        </p>
        <Button @click="openAdd">{{ __('+ Add bookable item', 'bookyzo') }}</Button>
      </Card>

      <Card v-else-if="filteredItems.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        {{ __('No bookable items match this filter.', 'bookyzo') }}
      </Card>

      <Card v-else class="overflow-hidden">
        <Table class="min-w-[900px]">
          <TableHeader>
            <TableRow>
              <TableHead class="min-w-[170px]">{{ __('Name', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Type', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Duration', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Price', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Status', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Shortcode', 'bookyzo') }}</TableHead>
              <TableHead class="text-right">{{ __('Action', 'bookyzo') }}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="item in paginatedItems" :key="item.id">
              <TableCell class="min-w-[170px] font-medium">{{ item.name }}</TableCell>
              <TableCell><Badge variant="secondary">{{ TYPE_LABELS[item.type] || item.type }}</Badge></TableCell>
              <TableCell class="text-muted-foreground">{{ sprintf(/*! translators: %s: duration in minutes. */ __('%s min', 'bookyzo'), item.duration_minutes) }}</TableCell>
              <TableCell class="text-muted-foreground">{{ formatPrice(item.price) }}</TableCell>
              <TableCell><ByzoBadge :status="item.status">{{ statusLabel(item.status) }}</ByzoBadge></TableCell>
              <TableCell>
                <div class="flex items-center gap-1.5">
                  <!-- Truncated so the Action column stays on screen at
                       common laptop widths (it was cut off at 1440px) — the
                       full shortcode is in the tooltip and on the copy button. -->
                  <code class="block max-w-[13rem] truncate whitespace-nowrap rounded bg-muted px-1.5 py-0.5 text-xs" :title="shortcode(item)">{{ shortcode(item) }}</code>
                  <Button variant="ghost" size="icon" class="h-6 w-6" :title="__('Copy shortcode', 'bookyzo')" @click="copyShortcode(item)">
                    <Copy class="h-3.5 w-3.5" />
                  </Button>
                </div>
              </TableCell>
              <TableCell class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <Button variant="ghost" size="sm" @click="openEdit(item)">{{ __('Edit', 'bookyzo') }}</Button>
                  <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="askDelete(item)">{{ __('Delete', 'bookyzo') }}</Button>
                </div>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>

        <ByzoPagination v-model:page="currentPage" :total-pages="totalPages" :total="totalCount" :page-size="pageSize" />
      </Card>
    </div>

    <Dialog :open="!!deleteTarget" @update:open="(v) => { if (!v) deleteTarget = null; }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{ __('Delete bookable item', 'bookyzo') }}</DialogTitle>
          <DialogDescription>
            {{ sprintf(/*! translators: %s: bookable item name. */ __('Delete "%s"? This cannot be undone.', 'bookyzo'), deleteTarget?.name) }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="deleteTarget = null">{{ __('Cancel', 'bookyzo') }}</Button>
          <Button variant="destructive" @click="confirmDelete">{{ __('Delete', 'bookyzo') }}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <BookableItemWizard :open="wizardOpen" :item-id="editId" @update:open="(v) => !v && closeWizard()" @saved="load" />
  </div>
</template>

<script setup>
import { statusLabel } from '@/lib/status';
import { i18n } from '@/lib/i18n';
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { toast } from 'vue-sonner';
import { Copy } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import { formatMoney } from '@/lib/money';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import ByzoPagination from '@/components/base/ByzoPagination.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import BookableItemWizard from './BookableItemWizard.vue';
import { api } from '@/api/client';
import { useClientPagination } from '@/composables/useClientPagination';

const TYPES = ['appointment', 'table', 'event', 'slot'];
const TYPE_LABELS = { appointment: i18n.__('Appointment', 'bookyzo'), table: i18n.__('Table', 'bookyzo'), event: i18n.__('Event', 'bookyzo'), slot: i18n.__('Slot', 'bookyzo') };

const route = useRoute();
const router = useRouter();
const items = ref([]);
const loading = ref(true);
const deleteTarget = ref(null);
const typeFilter = ref('__all__');
// Add and Edit are the SAME popup wizard (BookableItemWizard.vue): with no
// item id it creates, with one it loads that item and saves changes. Which
// mode is open is driven by the ROUTE, not local state, so a reload or a
// shared link lands on the same open dialog, and other screens can open it
// by navigating (SetupWizard.vue's "Manage" / "Add another" push to
// bookable-items.edit / bookable-items.new). Both routes render this list
// component (see router/index.js), so opening or closing is just a URL
// swap — `replace`, not `push`, so the dialog doesn't pile up history
// entries and the browser's Back still leaves the list.
const wizardOpen = computed(() => route.name === 'bookable-items.new' || route.name === 'bookable-items.edit');
const editId = computed(() => (route.name === 'bookable-items.edit' && route.params.id ? String(route.params.id) : null));

function openAdd() {
  router.replace({ name: 'bookable-items.new' });
}

function openEdit(item) {
  router.replace({ name: 'bookable-items.edit', params: { id: item.id } });
}

function closeWizard() {
  router.replace({ name: 'bookable-items' });
}

const filteredItems = computed(() =>
  typeFilter.value === '__all__' ? items.value : items.value.filter((i) => i.type === typeFilter.value)
);

const { page: currentPage, total: totalCount, totalPages, pageItems: paginatedItems, pageSize, reset: resetPage } =
  useClientPagination(filteredItems, 10);
watch(typeFilter, resetPage);

async function load() {
  loading.value = true;
  try {
    items.value = await api.get('/bookable-items?per_page=100');
  } catch (e) {
    toast.error(e.message || i18n.__('Could not load bookable items.', 'bookyzo'));
  } finally {
    loading.value = false;
  }
}

function formatPrice(price) {
  return formatMoney(price);
}

// The `bookable_item_id` attribute already worked server-side since Phase 1
// (BYZO_Public_Widget::render_shortcode()) — this is purely the admin
// surfacing DETAILED-SPEC.md §A5 asked for (the CF7 pattern: every
// bookable item shows its own copyable shortcode right in the list).
function shortcode(item) {
  return `[bookyzo_booking bookable_item_id="${item.id}"]`;
}

async function copyShortcode(item) {
  try {
    await navigator.clipboard.writeText(shortcode(item));
    toast.success(i18n.__('Shortcode copied.', 'bookyzo'));
  } catch (e) {
    toast.error(i18n.__('Could not copy — select and copy manually.', 'bookyzo'));
  }
}

function askDelete(item) {
  deleteTarget.value = item;
}

async function confirmDelete() {
  const item = deleteTarget.value;
  if (!item) return;
  try {
    await api.delete(`/bookable-items/${item.id}`);
    items.value = items.value.filter((i) => i.id !== item.id);
    toast.success(i18n.sprintf(/*! translators: %s: name of the deleted bookable item or customer. */ i18n.__('"%s" deleted.', 'bookyzo'), item.name));
  } catch (e) {
    toast.error(e.message || i18n.__('Could not delete this bookable item.', 'bookyzo'));
  } finally {
    deleteTarget.value = null;
  }
}

onMounted(load);
</script>
