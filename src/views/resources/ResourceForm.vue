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
      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <Card v-else class="max-w-xl">
        <CardContent class="flex flex-col gap-4 pt-6">
          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="name">Name</label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>

          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="description">Description</label>
            <textarea
              id="description"
              v-model="form.description"
              rows="3"
              class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>

          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="tags">Tags</label>
            <input
              id="tags"
              v-model="form.tags"
              type="text"
              placeholder="e.g. haircut, senior stylist"
              class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            <p class="text-xs text-muted-foreground">Comma-separated.</p>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-medium" for="duration">Duration (minutes)</label>
              <input
                id="duration"
                v-model.number="form.duration_minutes"
                type="number"
                min="5"
                step="5"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
            </div>
            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-medium" for="price">Price</label>
              <input
                id="price"
                v-model="form.price"
                type="number"
                min="0"
                step="0.01"
                placeholder="0.00"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
            </div>
          </div>

          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium" for="status">Status</label>
            <select
              id="status"
              v-model="form.status"
              class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="archived">Archived</option>
            </select>
          </div>

          <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        </CardContent>
        <CardFooter class="justify-between">
          <Button variant="outline" @click="router.push({ name: 'resources' })">Cancel</Button>
          <Button :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</Button>
        </CardFooter>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import { api } from '@/api/client';

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const loading = ref(isEdit.value);
const saving = ref(false);
const error = ref('');

const form = ref({
  name: '',
  description: '',
  tags: '',
  duration_minutes: 30,
  price: '',
  status: 'active',
});

onMounted(async () => {
  if (!isEdit.value) {
    return;
  }
  try {
    const resource = await api.get(`/resources/${route.params.id}`);
    form.value = {
      name: resource.name,
      description: resource.description || '',
      tags: resource.tags || '',
      duration_minutes: resource.duration_minutes,
      price: resource.price === null ? '' : resource.price,
      status: resource.status,
    };
  } catch (e) {
    error.value = e.message || 'Could not load this resource.';
  } finally {
    loading.value = false;
  }
});

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
    // Free v1 (Appointment) is single-person by definition (DETAILED-SPEC.md
    // §A9) — not exposed as a form field since there's nothing to choose yet.
    capacity: 1,
  };

  if (!isEdit.value) {
    payload.type = 'appointment';
  }

  saving.value = true;
  try {
    if (isEdit.value) {
      await api.put(`/resources/${route.params.id}`, payload);
    } else {
      await api.post('/resources', payload);
    }
    router.push({ name: 'resources' });
  } catch (e) {
    error.value = e.message || 'Could not save this resource.';
  } finally {
    saving.value = false;
  }
}
</script>
