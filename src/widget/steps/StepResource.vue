<template>
  <div v-if="loading" class="empty">Loading…</div>
  <div v-else-if="error" class="error">{{ error }}</div>
  <div v-else-if="resources.length === 0" class="empty">No bookable services are available right now.</div>
  <div v-else-if="!autoSkipping">
    <h2>Choose a service</h2>
    <div class="resource-list">
      <button
        v-for="resource in resources"
        :key="resource.id"
        type="button"
        class="option-card"
        @click="$emit('selected', resource)"
      >
        <span class="name">{{ resource.name }}</span>
        <span class="meta">
          {{ resource.duration_minutes }} min<template v-if="resource.price !== null"> · {{ formatPrice(resource.price) }}</template>
        </span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { getPublicResources } from '../api';

// Auto-skips this whole step — straight to slot picking — whenever
// there's only one real choice to make: either the embed is pinned to
// a specific resource (config.resourceId, from the block/shortcode's
// own setting) or there's exactly one active resource site-wide (the
// common case on Free, which caps one active resource per type). This
// is a UX call, not a Free/Pro gate — nothing here is ever shown as
// disabled/unavailable, the step just doesn't render when it has
// nothing to add.
const props = defineProps({
  config: { type: Object, required: true },
});
const emit = defineEmits(['selected']);

const resources = ref([]);
const loading = ref(true);
const error = ref('');
const autoSkipping = ref(false);

function formatPrice(price) {
  return Number(price).toFixed(2);
}

onMounted(async () => {
  try {
    resources.value = await getPublicResources(props.config.type);

    let target = null;
    if (props.config.resourceId) {
      target = resources.value.find((r) => r.id === props.config.resourceId) || null;
    } else if (resources.value.length === 1) {
      target = resources.value[0];
    }

    if (target) {
      autoSkipping.value = true;
      emit('selected', target);
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
});
</script>
