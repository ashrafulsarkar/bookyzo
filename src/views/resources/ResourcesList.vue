<template>
  <div>
    <ByzoPageHeader title="Resources" subtitle="Your staff, tables, or bookable items.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
      </template>
      <template #actions>
        <Button @click="router.push({ name: 'resources.new' })">+ Add resource</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <div v-if="!loading && resources.length > 0" class="mb-4 flex flex-col gap-1.5">
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

      <Card v-else-if="resources.length === 0" class="flex flex-col items-center gap-3 py-16 text-center">
        <p class="font-medium">No resources yet</p>
        <p class="max-w-sm text-sm text-muted-foreground">
          Add your first staff member, table, or bookable item to start accepting bookings.
        </p>
        <Button @click="router.push({ name: 'resources.new' })">+ Add resource</Button>
      </Card>

      <Card v-else-if="filteredResources.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        No resources match this filter.
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
            <TableRow v-for="resource in filteredResources" :key="resource.id">
              <TableCell class="font-medium">{{ resource.name }}</TableCell>
              <TableCell><Badge variant="secondary">{{ TYPE_LABELS[resource.type] || resource.type }}</Badge></TableCell>
              <TableCell class="text-muted-foreground">{{ resource.duration_minutes }} min</TableCell>
              <TableCell class="text-muted-foreground">{{ formatPrice(resource.price) }}</TableCell>
              <TableCell><ByzoBadge :status="resource.status">{{ resource.status }}</ByzoBadge></TableCell>
              <TableCell>
                <div class="flex items-center gap-1.5">
                  <code class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ shortcode(resource) }}</code>
                  <Button variant="ghost" size="sm" class="h-6 px-2 text-xs" @click="copyShortcode(resource)">Copy</Button>
                </div>
              </TableCell>
              <TableCell class="text-right">
                <Button variant="ghost" size="sm" @click="router.push({ name: 'resources.edit', params: { id: resource.id } })">
                  Edit
                </Button>
                <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="askDelete(resource)">
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
          <DialogTitle>Delete resource</DialogTitle>
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

// All 4 enum values are listed here even though the type-first "Add
// resource" picker only offers 3 today (Event's occurrence model isn't
// built yet, see ResourceTypeStep.vue) — an Event resource created
// directly via REST before then should still filter/label correctly
// rather than falling back to a raw enum string.
const TYPES = ['appointment', 'table', 'event', 'slot'];
const TYPE_LABELS = { appointment: 'Appointment', table: 'Table', event: 'Event', slot: 'Slot' };

const router = useRouter();
const resources = ref([]);
const loading = ref(true);
const deleteTarget = ref(null);
const typeFilter = ref('__all__');

const filteredResources = computed(() =>
  typeFilter.value === '__all__' ? resources.value : resources.value.filter((r) => r.type === typeFilter.value)
);

async function load() {
  loading.value = true;
  try {
    resources.value = await api.get('/resources?per_page=100');
  } catch (e) {
    toast.error(e.message || 'Could not load resources.');
  } finally {
    loading.value = false;
  }
}

function formatPrice(price) {
  return price === null || price === undefined ? '—' : Number(price).toFixed(2);
}

// The `resource_id` attribute already worked server-side since Phase 1
// (BYZO_Public_Widget::render_shortcode()) — this is purely the admin
// surfacing DETAILED-SPEC.md §A5 asked for (the CF7 pattern: every
// resource shows its own copyable shortcode right in the list).
function shortcode(resource) {
  return `[bookyzo_booking resource_id="${resource.id}"]`;
}

async function copyShortcode(resource) {
  try {
    await navigator.clipboard.writeText(shortcode(resource));
    toast.success('Shortcode copied.');
  } catch (e) {
    toast.error('Could not copy — select and copy manually.');
  }
}

function askDelete(resource) {
  deleteTarget.value = resource;
}

async function confirmDelete() {
  const resource = deleteTarget.value;
  if (!resource) return;
  try {
    await api.delete(`/resources/${resource.id}`);
    resources.value = resources.value.filter((r) => r.id !== resource.id);
    toast.success(`"${resource.name}" deleted.`);
  } catch (e) {
    toast.error(e.message || 'Could not delete this resource.');
  } finally {
    deleteTarget.value = null;
  }
}

onMounted(load);
</script>
