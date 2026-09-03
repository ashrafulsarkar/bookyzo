<template>
  <div v-if="loading" class="empty">Loading…</div>
  <div v-else-if="error" class="error">{{ error }}</div>
  <div v-else-if="items.length === 0" class="empty">No bookable services are available right now.</div>
  <div v-else-if="!autoSkipping">
    <h2>Choose a service</h2>
    <div class="bookable-item-list">
      <button
        v-for="item in items"
        :key="item.id"
        type="button"
        class="option-card"
        @click="$emit('selected', item)"
      >
        <span class="name">{{ item.name }}</span>
        <span class="meta">
          {{ item.duration_minutes }} min<template v-if="item.price !== null"> · {{ formatPrice(item.price) }}</template>
        </span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { getPublicBookableItems } from '../api';

// Auto-skips this whole step — straight to slot picking — whenever
// there's only one real choice to make: either the embed is pinned to
// a specific bookable item (config.bookableItemId, from the
// block/shortcode's own setting) or there's exactly one active item
// site-wide (the common case on Free, which caps one active item per
// type). This is a UX call, not a Free/Pro gate — nothing here is ever
// shown as disabled/unavailable, the step just doesn't render when it
// has nothing to add.
const props = defineProps({
  config: { type: Object, required: true },
});
const emit = defineEmits(['selected']);

const items = ref([]);
const loading = ref(true);
const error = ref('');
const autoSkipping = ref(false);

function formatPrice(price) {
  return Number(price).toFixed(2);
}

onMounted(async () => {
  try {
    items.value = await getPublicBookableItems(props.config.type);

    let target = null;
    if (props.config.bookableItemId) {
      target = items.value.find((i) => i.id === props.config.bookableItemId) || null;
    } else if (items.value.length === 1) {
      target = items.value[0];
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
