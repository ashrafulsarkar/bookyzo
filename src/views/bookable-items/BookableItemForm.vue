<template>
  <div>
    <ByzoPageHeader :title="isEdit ? 'Edit bookable item' : 'Add bookable item'" subtitle="Staff member, table, or bookable item.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
        </svg>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <!-- Availability/Exceptions need a saved bookable item id (DETAILED-SPEC.md
           §A2/A3's Details/Availability/Exceptions tabs) — only shown once
           editing an existing item, not while creating a new one. -->
      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <!-- Type-first Add New flow (DETAILED-SPEC.md §A2/§5 item 6): the
           picker is the very first thing shown when creating, before any
           other field. Not shown on edit — type is immutable after
           creation (see BookableItemDetailsTab.vue's read-only type badge
           and BYZO_REST_Bookable_Items_Controller::update_item()'s matching
           server-side rejection of a type change). -->
      <BookableItemTypeStep v-else-if="!isEdit && !form.type" @selected="onTypeSelected" />

      <Tabs v-else-if="isEdit" v-model="activeTab">
        <TabsList class="mb-4">
          <TabsTrigger v-for="tab in tabs" :key="tab.id" :value="tab.id">{{ tab.label }}</TabsTrigger>
        </TabsList>
        <TabsContent value="details">
          <BookableItemDetailsTab
            :form="form"
            :saving="saving"
            :error="error"
            @save="save"
            @cancel="router.push({ name: 'bookable-items' })"
          />
        </TabsContent>
        <!-- Event items have no weekly schedule (DETAILED-SPEC.md §1.3) —
             a Dates tab replaces Availability/Exceptions entirely, it
             doesn't sit alongside them. -->
        <TabsContent v-if="form.type === 'event'" value="dates">
          <BookableItemDatesTab :bookable-item-id="route.params.id" />
        </TabsContent>
        <template v-else>
          <TabsContent value="availability">
            <BookableItemAvailabilityTab :bookable-item-id="route.params.id" />
          </TabsContent>
          <TabsContent value="exceptions">
            <BookableItemExceptionsTab :bookable-item-id="route.params.id" />
          </TabsContent>
        </template>
      </Tabs>

      <BookableItemDetailsTab
        v-else
        :form="form"
        :saving="saving"
        :error="error"
        @save="save"
        @cancel="router.push({ name: 'bookable-items' })"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import BookableItemTypeStep from './BookableItemTypeStep.vue';
import BookableItemDetailsTab from './BookableItemDetailsTab.vue';
import BookableItemAvailabilityTab from './BookableItemAvailabilityTab.vue';
import BookableItemExceptionsTab from './BookableItemExceptionsTab.vue';
import BookableItemDatesTab from './BookableItemDatesTab.vue';
import { api } from '@/api/client';

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const loading = ref(isEdit.value);
const saving = ref(false);
const error = ref('');
const activeTab = ref('details');

// Event items get a single "Dates" tab instead of Availability/Exceptions
// (DETAILED-SPEC.md §1.3 — no weekly schedule to configure).
const tabs = computed(() =>
  form.value.type === 'event'
    ? [
        { id: 'details', label: 'Details' },
        { id: 'dates', label: 'Dates' },
      ]
    : [
        { id: 'details', label: 'Details' },
        { id: 'availability', label: 'Availability' },
        { id: 'exceptions', label: 'Exceptions' },
      ]
);

const form = ref({
  // Empty on a fresh create — BookableItemTypeStep must be answered first
  // (the `!form.type` check above gates on exactly this). Overwritten
  // with the real value on edit (see onMounted below).
  type: '',
  name: '',
  description: '',
  tags: '',
  duration_minutes: 30,
  price: '',
  capacity: 1,
  status: 'active',
});

onMounted(async () => {
  if (!isEdit.value) {
    return;
  }
  try {
    const item = await api.get(`/bookable-items/${route.params.id}`);
    form.value = {
      type: item.type,
      name: item.name,
      description: item.description || '',
      tags: item.tags || '',
      duration_minutes: item.duration_minutes,
      price: item.price === null ? '' : item.price,
      capacity: item.capacity || 1,
      status: item.status,
    };
  } catch (e) {
    error.value = e.message || 'Could not load this bookable item.';
  } finally {
    loading.value = false;
  }
});

function onTypeSelected(type) {
  form.value.type = type;
}

async function save() {
  error.value = '';
  if (!form.value.name.trim()) {
    error.value = 'Please enter a name.';
    return;
  }

  const payload = {
    name: form.value.name,
    description: form.value.description,
    tags: form.value.tags,
    duration_minutes: form.value.duration_minutes,
    price: form.value.price === '' ? null : form.value.price,
    status: form.value.status,
    // Appointment is single-person by definition (DETAILED-SPEC.md §A2/A3
    // field matrix) regardless of what the hidden field holds; every other
    // type sends its real capacity.
    capacity: form.value.type === 'appointment' ? 1 : Math.max(1, Number(form.value.capacity) || 1),
  };

  if (!isEdit.value) {
    payload.type = form.value.type;
  }

  saving.value = true;
  try {
    if (isEdit.value) {
      await api.put(`/bookable-items/${route.params.id}`, payload);
      router.push({ name: 'bookable-items' });
    } else {
      const created = await api.post('/bookable-items', payload);
      // Straight to the new item's Availability tab (or Dates, for an
      // event) — a bookable item with no schedule/dates generates zero
      // slots (BYZO_Slot_Generator / BYZO_Slot::create_occurrence()), so
      // this is the natural next step, not a dead end at the list.
      // router.push alone doesn't do this: navigating from /new to
      // /:id/edit resolves to the same route component, so Vue Router
      // reuses this instance rather than remounting it, and activeTab's
      // ref('details') default is never re-evaluated on its own.
      activeTab.value = form.value.type === 'event' ? 'dates' : 'availability';
      router.push({ name: 'bookable-items.edit', params: { id: created.id } });
    }
  } catch (e) {
    error.value = e.message || 'Could not save this bookable item.';
  } finally {
    saving.value = false;
  }
}
</script>
