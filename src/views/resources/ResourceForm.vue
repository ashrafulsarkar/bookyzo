<template>
  <div>
    <ByzoPageHeader :title="isEdit ? 'Edit resource' : 'Add resource'" subtitle="Staff member, table, or bookable item.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
        </svg>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <!-- Availability/Exceptions need a saved resource id (DETAILED-SPEC.md
           §A2/A3's Details/Availability/Exceptions tabs) — only shown once
           editing an existing resource, not while creating a new one. -->
      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <!-- Type-first Add New flow (DETAILED-SPEC.md §A2/§5 item 6): the
           picker is the very first thing shown when creating, before any
           other field. Not shown on edit — type is immutable after
           creation (see ResourceDetailsTab.vue's read-only type badge and
           BYZO_REST_Resources_Controller::update_item()'s matching
           server-side rejection of a type change). -->
      <ResourceTypeStep v-else-if="!isEdit && !form.type" @selected="onTypeSelected" />

      <Tabs v-else-if="isEdit" v-model="activeTab">
        <TabsList class="mb-4">
          <TabsTrigger v-for="tab in tabs" :key="tab.id" :value="tab.id">{{ tab.label }}</TabsTrigger>
        </TabsList>
        <TabsContent value="details">
          <ResourceDetailsTab
            :form="form"
            :saving="saving"
            :error="error"
            @save="save"
            @cancel="router.push({ name: 'resources' })"
          />
        </TabsContent>
        <TabsContent value="availability">
          <ResourceAvailabilityTab :resource-id="route.params.id" />
        </TabsContent>
        <TabsContent value="exceptions">
          <ResourceExceptionsTab :resource-id="route.params.id" />
        </TabsContent>
      </Tabs>

      <ResourceDetailsTab
        v-else
        :form="form"
        :saving="saving"
        :error="error"
        @save="save"
        @cancel="router.push({ name: 'resources' })"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ResourceTypeStep from './ResourceTypeStep.vue';
import ResourceDetailsTab from './ResourceDetailsTab.vue';
import ResourceAvailabilityTab from './ResourceAvailabilityTab.vue';
import ResourceExceptionsTab from './ResourceExceptionsTab.vue';
import { api } from '@/api/client';

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const loading = ref(isEdit.value);
const saving = ref(false);
const error = ref('');
const activeTab = ref('details');

const tabs = [
  { id: 'details', label: 'Details' },
  { id: 'availability', label: 'Availability' },
  { id: 'exceptions', label: 'Exceptions' },
];

const form = ref({
  // Empty on a fresh create — ResourceTypeStep must be answered first
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
    const resource = await api.get(`/resources/${route.params.id}`);
    form.value = {
      type: resource.type,
      name: resource.name,
      description: resource.description || '',
      tags: resource.tags || '',
      duration_minutes: resource.duration_minutes,
      price: resource.price === null ? '' : resource.price,
      capacity: resource.capacity || 1,
      status: resource.status,
    };
  } catch (e) {
    error.value = e.message || 'Could not load this resource.';
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
      await api.put(`/resources/${route.params.id}`, payload);
      router.push({ name: 'resources' });
    } else {
      const created = await api.post('/resources', payload);
      // Straight to the new resource's Availability tab — a resource
      // with no schedule generates zero slots (BYZO_Slot_Generator), so
      // this is the natural next step, not a dead end at the list.
      router.push({ name: 'resources.edit', params: { id: created.id } });
    }
  } catch (e) {
    error.value = e.message || 'Could not save this resource.';
  } finally {
    saving.value = false;
  }
}
</script>
