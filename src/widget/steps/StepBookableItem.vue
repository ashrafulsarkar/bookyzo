<template>
  <WidgetLoading v-if="loading" />
  <div v-else-if="error" class="error">{{ error }}</div>
  <div v-else-if="items.length === 0" class="empty">No bookable services are available right now.</div>
  <div v-else-if="!autoSkipping">
    <h2>{{ copy.pickHeading }}</h2>
    <p class="step-sub">{{ copy.pickSub }}</p>
    <div class="bookable-item-list">
      <button
        v-for="item in items"
        :key="item.id"
        type="button"
        class="option-card"
        @click="$emit('selected', item, false)"
      >
        <span class="option-card-body">
          <span class="name">{{ item.name }}</span>
          <span class="meta">
            <span class="meta-item">{{ item.duration_minutes }} min</span>
            <span v-if="item.price !== null" class="meta-price">{{ formatPrice(item.price) }}</span>
          </span>
        </span>
        <span class="option-card-go" aria-hidden="true">&#8250;</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { getPublicBookableItems } from '../api';
import { copyFor, MIXED_COPY } from '../copy';
import WidgetLoading from '../WidgetLoading.vue';
import { formatMoney } from '@/lib/money';

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
  return formatMoney(price);
}

// A picker filtered to one type (or a site that only sells one) can speak
// that type's language; a genuinely mixed list can't, so it stays neutral.
const copy = computed(() => {
  const types = new Set(items.value.map((i) => i.type));
  return types.size === 1 ? copyFor(items.value[0].type) : MIXED_COPY;
});

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
      emit('selected', target, true);
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
});
</script>
