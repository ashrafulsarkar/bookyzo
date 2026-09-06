<template>
  <div>
    <ByzoPageHeader title="Customers" subtitle="Everyone who's booked with you.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
      </template>
      <template #actions>
        <form ref="importForm" :action="importUrl" method="post" enctype="multipart/form-data" class="inline">
          <input type="hidden" name="action" value="byzo_import_customers" />
          <input type="hidden" name="_wpnonce" :value="importNonce" />
          <input ref="fileInput" type="file" name="customers_csv" accept=".csv,text/csv" class="hidden" @change="onFileChosen" />
        </form>
        <Button variant="outline" @click="fileInput?.click()">Import CSV</Button>
        <Button @click="openCreate">+ Add customer</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <div class="mb-4 flex flex-col gap-1.5">
        <Label class="text-xs font-medium text-muted-foreground" for="c-search">Search</Label>
        <div class="relative w-72">
          <SearchIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input id="c-search" v-model="search" type="text" placeholder="Name, email, or phone…" class="h-9 !pl-9 !pr-8" />
          <button
            v-if="search"
            type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
            aria-label="Clear search"
            @click="search = ''"
          >
            <X class="h-4 w-4" />
          </button>
        </div>
      </div>

      <ByzoLoading v-if="loading" />

      <Card v-else-if="items.length === 0 && !search" class="flex flex-col items-center gap-3 py-16 text-center">
        <p class="font-medium">No customers yet</p>
        <p class="max-w-sm text-sm text-muted-foreground">
          Customers appear here automatically the first time they book, or you can add one yourself or import a CSV list.
        </p>
      </Card>

      <Card v-else-if="items.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        No customers match this search.
      </Card>

      <Card v-else class="overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Phone</TableHead>
              <TableHead>Tags</TableHead>
              <TableHead>Source</TableHead>
              <TableHead class="text-right">Action</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="customer in paginatedItems" :key="customer.id">
              <TableCell class="font-medium">{{ customer.name }}</TableCell>
              <TableCell class="text-muted-foreground">{{ customer.email }}</TableCell>
              <TableCell class="text-muted-foreground">{{ customer.phone || '—' }}</TableCell>
              <TableCell class="text-muted-foreground">{{ customer.tags || '—' }}</TableCell>
              <TableCell><Badge variant="secondary">{{ customer.source }}</Badge></TableCell>
              <TableCell class="text-right">
                <Button variant="ghost" size="sm" @click="openEdit(customer)">Edit</Button>
                <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="askDelete(customer)">Delete</Button>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>

        <ByzoPagination v-model:page="currentPage" :total-pages="totalPages" :total="totalCount" :page-size="pageSize" />
      </Card>
    </div>

    <Dialog :open="!!editing" @update:open="(v) => { if (!v) editing = null; }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{ editing?.id ? 'Edit customer' : 'Add customer' }}</DialogTitle>
        </DialogHeader>
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <Label for="cf-name">Name</Label>
            <Input id="cf-name" v-model="form.name" type="text" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="cf-email">Email</Label>
            <Input id="cf-email" v-model="form.email" type="email" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="cf-phone">Phone</Label>
            <Input id="cf-phone" v-model="form.phone" type="text" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="cf-tags">Tags</Label>
            <Input id="cf-tags" v-model="form.tags" type="text" placeholder="e.g. vip, regular" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="cf-notes">Notes</Label>
            <Textarea id="cf-notes" v-model="form.notes" rows="3" />
          </div>
          <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="editing = null">Cancel</Button>
          <Button :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="!!deleteTarget" @update:open="(v) => { if (!v) deleteTarget = null; }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete customer</DialogTitle>
          <DialogDescription>
            Delete "{{ deleteTarget?.name }}"? This only removes their customer record — past bookings are unaffected.
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
import { ref, reactive, watch, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Search as SearchIcon, X } from 'lucide-vue-next';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoPagination from '@/components/base/ByzoPagination.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import { api } from '@/api/client';
import { useClientPagination } from '@/composables/useClientPagination';

const items = ref([]);
const loading = ref(true);
const search = ref('');
const editing = ref(null);
const deleteTarget = ref(null);
const saving = ref(false);
const formError = ref('');
const fileInput = ref(null);
const importForm = ref(null);

const form = reactive({ name: '', email: '', phone: '', tags: '', notes: '' });

const { page: currentPage, total: totalCount, totalPages, pageItems: paginatedItems, pageSize, reset: resetPage } =
  useClientPagination(items, 10);

// admin-post.php isn't part of the REST API, so this rides its own
// nonce/URL from window.byzoAdmin (see BYZO_Admin_Assets), same pattern
// BookingsList.vue's CSV export link already uses — a real <form> POST
// rather than fetch/XHR since a multipart file upload has nowhere to go
// in wp.apiFetch's JSON-only world, and BYZO_Customer_Import::handle()
// redirects back here with ?byzo_imported=/&byzo_skipped= rather than
// returning JSON.
const importUrl = window.byzoAdmin?.importCustomersUrl || '#';
const importNonce = window.byzoAdmin?.importCustomersNonce || '';

function onFileChosen() {
  if (fileInput.value?.files?.length) {
    importForm.value?.submit();
  }
}

async function load() {
  loading.value = true;
  try {
    items.value = await api.get(`/customers?per_page=100&search=${encodeURIComponent(search.value)}`);
  } catch (e) {
    toast.error(e.message || 'Could not load customers.');
  } finally {
    loading.value = false;
  }
}

let debounceTimer = null;
watch(search, () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => { resetPage(); load(); }, 300);
});

function openCreate() {
  formError.value = '';
  Object.assign(form, { id: null, name: '', email: '', phone: '', tags: '', notes: '' });
  editing.value = { id: null };
}

function openEdit(customer) {
  formError.value = '';
  Object.assign(form, customer);
  editing.value = customer;
}

async function save() {
  formError.value = '';
  saving.value = true;
  try {
    const payload = { name: form.name, email: form.email, phone: form.phone, tags: form.tags, notes: form.notes };
    if (editing.value?.id) {
      const updated = await api.put(`/customers/${editing.value.id}`, payload);
      items.value = items.value.map((c) => (c.id === updated.id ? updated : c));
      toast.success('Customer updated.');
    } else {
      const created = await api.post('/customers', payload);
      items.value = [created, ...items.value];
      toast.success('Customer added.');
    }
    editing.value = null;
  } catch (e) {
    formError.value = e.message || 'Could not save this customer.';
  } finally {
    saving.value = false;
  }
}

function askDelete(customer) {
  deleteTarget.value = customer;
}

async function confirmDelete() {
  const customer = deleteTarget.value;
  if (!customer) return;
  try {
    await api.delete(`/customers/${customer.id}`);
    items.value = items.value.filter((c) => c.id !== customer.id);
    toast.success(`"${customer.name}" deleted.`);
  } catch (e) {
    toast.error(e.message || 'Could not delete this customer.');
  } finally {
    deleteTarget.value = null;
  }
}

// After BYZO_Customer_Import::handle() redirects back here, the result
// rides in the URL as ?byzo_imported=&byzo_skipped= (an admin-post.php
// form POST has no JSON response to read instead) — surface it once as
// a toast, then strip those two params so a reload/bookmark of this
// screen doesn't keep re-showing a stale import result.
onMounted(() => {
  const params = new URLSearchParams(window.location.search);
  const imported = params.get('byzo_imported');
  const skipped = params.get('byzo_skipped');
  const error = params.get('byzo_import_error');

  if (null !== imported) {
    toast.success(`Imported ${imported} customer${imported === '1' ? '' : 's'}${skipped && skipped !== '0' ? `, skipped ${skipped}` : ''}.`);
  } else if (error) {
    toast.error(error === 'no_file' ? 'Please choose a CSV file to import.' : 'Could not read that file.');
  }

  if (null !== imported || error) {
    params.delete('byzo_imported');
    params.delete('byzo_skipped');
    params.delete('byzo_import_error');
    const clean = window.location.pathname + '?' + params.toString() + window.location.hash;
    window.history.replaceState(window.history.state, '', clean);
  }

  load();
});
</script>
