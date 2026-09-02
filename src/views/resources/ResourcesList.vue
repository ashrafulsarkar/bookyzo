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
      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>

      <Card v-else-if="resources.length === 0" class="flex flex-col items-center gap-3 py-16 text-center">
        <p class="font-medium">No resources yet</p>
        <p class="max-w-sm text-sm text-muted-foreground">
          Add your first staff member, table, or bookable item to start accepting bookings.
        </p>
        <Button @click="router.push({ name: 'resources.new' })">+ Add resource</Button>
      </Card>

      <Card v-else class="overflow-hidden">
        <table class="w-full text-sm">
          <thead class="border-b border-border bg-muted/50 text-left text-xs uppercase text-muted-foreground">
            <tr>
              <th class="px-4 py-3 font-medium">Name</th>
              <th class="px-4 py-3 font-medium">Duration</th>
              <th class="px-4 py-3 font-medium">Price</th>
              <th class="px-4 py-3 font-medium">Status</th>
              <th class="px-4 py-3 font-medium"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="resource in resources" :key="resource.id" class="border-b border-border last:border-0">
              <td class="px-4 py-3 font-medium">{{ resource.name }}</td>
              <td class="px-4 py-3 text-muted-foreground">{{ resource.duration_minutes }} min</td>
              <td class="px-4 py-3 text-muted-foreground">{{ formatPrice(resource.price) }}</td>
              <td class="px-4 py-3"><ByzoBadge :status="resource.status">{{ resource.status }}</ByzoBadge></td>
              <td class="px-4 py-3 text-right">
                <Button variant="ghost" size="sm" @click="router.push({ name: 'resources.edit', params: { id: resource.id } })">
                  Edit
                </Button>
                <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="confirmDelete(resource)">
                  Delete
                </Button>
              </td>
            </tr>
          </tbody>
        </table>
      </Card>

      <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import { api } from '@/api/client';

const router = useRouter();
const resources = ref([]);
const loading = ref(true);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    resources.value = await api.get('/resources?per_page=100');
  } catch (e) {
    error.value = e.message || 'Could not load resources.';
  } finally {
    loading.value = false;
  }
}

function formatPrice(price) {
  return price === null || price === undefined ? '—' : Number(price).toFixed(2);
}

async function confirmDelete(resource) {
  if (!window.confirm(`Delete "${resource.name}"? This cannot be undone.`)) {
    return;
  }
  try {
    await api.delete(`/resources/${resource.id}`);
    resources.value = resources.value.filter((r) => r.id !== resource.id);
  } catch (e) {
    error.value = e.message || 'Could not delete this resource.';
  }
}

onMounted(load);
</script>
