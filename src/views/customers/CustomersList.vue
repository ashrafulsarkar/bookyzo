<template>
  <div>
    <ByzoPageHeader :title="__('Customers', 'bookyzo')" :subtitle="__('Everyone who\'s booked with you.', 'bookyzo')">
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
        <Button variant="outline" @click="fileInput?.click()">{{ __('Import CSV', 'bookyzo') }}</Button>
        <!-- An add-on's own header actions (admin-extensions.js
             extraCustomerListActions), e.g. a customer export. -->
        <a v-for="action in extraCustomerListActions" :key="action.label" :href="action.href" class="inline-flex">
          <Button variant="outline">{{ action.label }}</Button>
        </a>
        <Button @click="openCreate">{{ __('+ Add customer', 'bookyzo') }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <div class="mb-4 flex flex-col gap-1.5">
        <Label class="text-xs font-medium text-muted-foreground" for="c-search">{{ __('Search', 'bookyzo') }}</Label>
        <div class="relative w-72">
          <SearchIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input id="c-search" v-model="search" type="text" :placeholder="__('Name, email, or phone…', 'bookyzo')" class="h-9 !pl-9 !pr-8" />
          <button
            v-if="search"
            type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
            :aria-label="__('Clear search', 'bookyzo')"
            @click="search = ''"
          >
            <X class="h-4 w-4" />
          </button>
        </div>
      </div>

      <ByzoLoading v-if="loading" />

      <Card v-else-if="items.length === 0 && !search" class="flex flex-col items-center gap-3 py-16 text-center">
        <p class="font-medium">{{ __('No customers yet', 'bookyzo') }}</p>
        <p class="max-w-sm text-sm text-muted-foreground">
          {{ __('Customers appear here automatically the first time they book, or you can add one yourself or import a CSV list.', 'bookyzo') }}
        </p>
      </Card>

      <Card v-else-if="items.length === 0" class="py-16 text-center text-sm text-muted-foreground">
        {{ __('No customers match this search.', 'bookyzo') }}
      </Card>

      <Card v-else class="overflow-hidden">
        <Table class="min-w-[760px]">
          <TableHeader>
            <TableRow>
              <TableHead>{{ __('Name', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Email', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Phone', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Tags', 'bookyzo') }}</TableHead>
              <TableHead>{{ __('Source', 'bookyzo') }}</TableHead>
              <TableHead class="text-right">{{ __('Action', 'bookyzo') }}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="customer in paginatedItems" :key="customer.id">
              <TableCell class="font-medium">{{ customer.name }}</TableCell>
              <TableCell class="text-muted-foreground">{{ customer.email }}</TableCell>
              <TableCell class="text-muted-foreground">{{ customer.phone || '—' }}</TableCell>
              <TableCell class="text-muted-foreground">{{ customer.tags || '—' }}</TableCell>
              <TableCell><Badge variant="secondary">{{ SOURCE_LABELS[customer.source] || customer.source }}</Badge></TableCell>
              <TableCell class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <Button variant="ghost" size="sm" @click="openEdit(customer)">{{ __('Edit', 'bookyzo') }}</Button>
                  <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="askDelete(customer)">{{ __('Delete', 'bookyzo') }}</Button>
                </div>
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
          <DialogTitle>{{ editing?.id ? __('Edit customer', 'bookyzo') : __('Add customer', 'bookyzo') }}</DialogTitle>
        </DialogHeader>
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <Label for="cf-name">{{ __('Name', 'bookyzo') }}</Label>
            <Input id="cf-name" v-model="form.name" type="text" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="cf-email">{{ __('Email', 'bookyzo') }}</Label>
            <Input id="cf-email" v-model="form.email" type="email" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="cf-phone">{{ __('Phone', 'bookyzo') }}</Label>
            <Input id="cf-phone" v-model="form.phone" type="text" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="cf-tags">{{ __('Tags', 'bookyzo') }}</Label>
            <Input id="cf-tags" v-model="form.tags" type="text" :placeholder="__('e.g. vip, regular', 'bookyzo')" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="cf-notes">{{ __('Notes', 'bookyzo') }}</Label>
            <Textarea id="cf-notes" v-model="form.notes" rows="3" />
          </div>
          <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="editing = null">{{ __('Cancel', 'bookyzo') }}</Button>
          <Button :disabled="saving" @click="save">{{ saving ? __('Saving…', 'bookyzo') : __('Save', 'bookyzo') }}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="!!deleteTarget" @update:open="(v) => { if (!v) deleteTarget = null; }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{ __('Delete customer', 'bookyzo') }}</DialogTitle>
          <DialogDescription>
            {{ sprintf(/*! translators: %s: customer name. */ __('Delete "%s"? This only removes their customer record — past bookings are unaffected.', 'bookyzo'), deleteTarget?.name) }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="deleteTarget = null">{{ __('Cancel', 'bookyzo') }}</Button>
          <Button variant="destructive" @click="confirmDelete">{{ __('Delete', 'bookyzo') }}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
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
import { extraCustomerListActions } from '@/lib/admin-extensions';

const SOURCE_LABELS = {
  booking: i18n.__('Booking', 'bookyzo'),
  import: i18n.__('Import', 'bookyzo'),
};

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
    toast.error(e.message || i18n.__('Could not load customers.', 'bookyzo'));
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
      toast.success(i18n.__('Customer updated.', 'bookyzo'));
    } else {
      const created = await api.post('/customers', payload);
      items.value = [created, ...items.value];
      toast.success(i18n.__('Customer added.', 'bookyzo'));
    }
    editing.value = null;
  } catch (e) {
    formError.value = e.message || i18n.__('Could not save this customer.', 'bookyzo');
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
    toast.success(i18n.sprintf(/*! translators: %s: name of the deleted bookable item or customer. */ i18n.__('"%s" deleted.', 'bookyzo'), customer.name));
  } catch (e) {
    toast.error(e.message || i18n.__('Could not delete this customer.', 'bookyzo'));
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
        const importedText = i18n.sprintf(/*! translators: %s: number of customers imported. */ i18n._n('Imported %s customer.', 'Imported %s customers.', Number(imported), 'bookyzo'), imported);
    const skippedText = skipped && skipped !== '0' ? ' ' + i18n.sprintf(/*! translators: %s: number of CSV rows skipped. */ i18n._n('Skipped %s row.', 'Skipped %s rows.', Number(skipped), 'bookyzo'), skipped) : '';
    toast.success(importedText + skippedText);
  } else if (error) {
    toast.error(error === 'no_file' ? i18n.__('Please choose a CSV file to import.', 'bookyzo') : i18n.__('Could not read that file.', 'bookyzo'));
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
