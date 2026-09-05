<template>
  <div>
    <ByzoPageHeader title="Bookable Items" subtitle="Your staff, tables, or bookable items.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
      </template>
      <template #actions>
        <Button @click="router.push({ name: 'bookable-items.new' })">+ Add bookable item</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <div v-if="!loading && items.length > 0" class="mb-4 flex flex-col gap-1.5">
        <Label class="text-xs text-muted-foreground">Type</Label>
        <Select v-model="typeFilter">
          <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">All</SelectItem>
            <SelectItem v-for="type in TYPES" :key="type" :value="type">{{ TYPE_LABELS[type] }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <Card v-else-if="items.length === 0" class="flex flex-col items-center gap-3 py-16 text-center">
        <p class="font-medium">No bookable items yet</p>
        <p class="max-w-sm text-sm text-muted-foreground">
          Add your first staff member, table, or bookable item to start accepting bookings.
        </p>
        <Button @click="router.push({ name: 'bookable-items.new' })">+ Add bookable item</Button>
      </Card>

      <Card v-else-if="filteredItems.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        No bookable items match this filter.
      </Card>

      <Card v-else class="overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Duration</TableHead>
              <TableHead>Price</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Shortcode</TableHead>
              <TableHead></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="item in filteredItems" :key="item.id">
              <TableCell class="font-medium">{{ item.name }}</TableCell>
              <TableCell><Badge variant="secondary">{{ TYPE_LABELS[item.type] || item.type }}</Badge></TableCell>
              <TableCell class="text-muted-foreground">{{ item.duration_minutes }} min</TableCell>
              <TableCell class="text-muted-foreground">{{ formatPrice(item.price) }}</TableCell>
              <TableCell><ByzoBadge :status="item.status">{{ item.status }}</ByzoBadge></TableCell>
              <TableCell>
                <div class="flex items-center gap-1.5">
                  <code class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ shortcode(item) }}</code>
                  <Button variant="ghost" size="sm" class="h-6 px-2 text-xs" @click="copyShortcode(item)">Copy</Button>
                </div>
              </TableCell>
              <TableCell class="text-right">
                <Button variant="ghost" size="sm" @click="router.push({ name: 'bookable-items.edit', params: { id: item.id } })">
                  Edit
                </Button>
                <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="askDelete(item)">
                  Delete
                </Button>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </Card>
    </div>

    <Dialog :open="!!deleteTarget" @update:open="(v) => { if (!v) deleteTarget = null; }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete bookable item</DialogTitle>
          <DialogDescription>
            Delete "{{ deleteTarget?.name }}"? This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="deleteTarget = null">Cancel</Button>
          <Button variant="destructive" @click="confirmDelete">Delete</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import { api } from '@/api/client';

const TYPES = ['appointment', 'table', 'event', 'slot'];
const TYPE_LABELS = { appointment: 'Appointment', table: 'Table', event: 'Event', slot: 'Slot' };

const router = useRouter();
const items = ref([]);
const loading = ref(true);
const deleteTarget = ref(null);
const typeFilter = ref('__all__');

const filteredItems = computed(() =>
  typeFilter.value === '__all__' ? items.value : items.value.filter((i) => i.type === typeFilter.value)
);

async function load() {
  loading.value = true;
  try {
    items.value = await api.get('/bookable-items?per_page=100');
  } catch (e) {
    toast.error(e.message || 'Could not load bookable items.');
  } finally {
    loading.value = false;
  }
}

function formatPrice(price) {
  return price === null || price === undefined ? '—' : Number(price).toFixed(2);
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
    toast.success('Shortcode copied.');
  } catch (e) {
    toast.error('Could not copy — select and copy manually.');
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
    toast.success(`"${item.name}" deleted.`);
  } catch (e) {
    toast.error(e.message || 'Could not delete this bookable item.');
  } finally {
    deleteTarget.value = null;
  }
}

onMounted(load);
</script>
