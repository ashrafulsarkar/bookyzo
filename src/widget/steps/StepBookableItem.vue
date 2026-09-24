<template>
  <WidgetLoading v-if="loading" />
  <div v-else-if="error" class="error">{{ error }}</div>
  <div v-else-if="items.length === 0 && services.length === 0" class="empty">No bookable services are available right now.</div>
  <div v-else-if="!autoSkipping">
    <h2>{{ copy.pickHeading }}</h2>
    <p class="step-sub">{{ copy.pickSub }}</p>
    <!-- Pro services (QA report 2026-09-22 B9): picked by what, not by
         whom — the next step offers the staff who provide it, or "any
         available". Absent when there are none (always, without Pro). -->
    <p v-if="services.length && items.length" class="group-label">Services</p>
    <div v-if="services.length" class="bookable-item-list">
      <button
        v-for="service in services"
        :key="`service-${service.id}`"
        type="button"
        class="option-card"
        @click="$emit('selected-service', service, items)"
      >
        <span class="option-card-body">
          <span class="name">{{ service.name }}</span>
          <span class="meta">
            <span class="meta-item">{{ serviceDuration(service) }} min</span>
            <span v-if="servicePrice(service)" class="meta-price">{{ servicePrice(service) }}</span>
          </span>
        </span>
        <span class="option-card-go" aria-hidden="true">&#8250;</span>
      </button>
    </div>
    <!-- Grouped by type once there's more than one, so tables, classes and
         events don't sit in one undifferentiated list (QA report §4.14). -->
    <template v-for="(group, index) in groups" :key="group.type">
      <p v-if="groups.length > 1 || services.length" class="group-label">{{ group.label }}</p>
      <div class="bookable-item-list" :class="{ 'bookable-item-list--after-services': index === 0 && services.length && groups.length === 1 }">
        <button
          v-for="item in group.items"
          :key="item.id"
          type="button"
          class="option-card"
          @click="$emit('selected', item, false)"
        >
          <span class="option-card-body">
            <span class="name">{{ item.name }}</span>
            <span class="meta">
              <span class="meta-item">{{ item.duration_minutes }} min</span>
              <span v-if="item.capacity > 1" class="meta-item">{{ capacityLabel(item) }}</span>
              <span v-if="item.price !== null" class="meta-price">{{ formatPrice(item.price) }}</span>
            </span>
          </span>
          <span class="option-card-go" aria-hidden="true">&#8250;</span>
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { getPublicBookableItems, getPublicServices } from '../api';
import { copyFor, MIXED_COPY, GROUP_LABELS, GROUP_ORDER } from '../copy';
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
const emit = defineEmits(['selected', 'selected-service']);

const items = ref([]);
const services = ref([]);
const loading = ref(true);
const error = ref('');
const autoSkipping = ref(false);

function formatPrice(price) {
  return formatMoney(price);
}

// A service's staff can differ in length and price — show the shortest
// and "from" the lowest, the same way the staff step's "Any available" does.
function serviceDuration(service) {
  return Math.min(...service.staff.map((m) => m.duration_minutes));
}
function servicePrice(service) {
  const prices = service.staff.map((m) => m.price).filter((p) => p !== null);
  if (!prices.length) return '';
  const low = Math.min(...prices);
  return prices.every((p) => p === low) ? formatMoney(low) : `from ${formatMoney(low)}`;
}

// A picker filtered to one type (or a site that only sells one) can speak
// that type's language; a genuinely mixed list can't, so it stays neutral.
const copy = computed(() => {
  const types = new Set(items.value.map((i) => i.type));
  return types.size === 1 && !services.value.length ? copyFor(items.value[0].type) : MIXED_COPY;
});

// One group per type, in a fixed order, each keeping the server's own
// ordering inside it.
const groups = computed(() =>
  GROUP_ORDER.map((type) => ({
    type,
    // With Pro services listed above, "Services" twice would be two
    // headings for two different things — the staff themselves come second.
    label: 'appointment' === type && services.value.length ? 'Or book with' : GROUP_LABELS[type],
    items: items.value.filter((item) => item.type === type),
  })).filter((group) => group.items.length)
);

// How many a table seats, a class takes, an event sells — in that type's
// own words rather than one generic "party size".
function capacityLabel(item) {
  const labels = {
    table: `Seats ${item.capacity}`,
    slot: `${item.capacity} spots`,
    event: `${item.capacity} tickets`,
  };
  return labels[item.type] || '';
}

onMounted(async () => {
  try {
    items.value = await getPublicBookableItems(props.config.type);

    // Services are appointments, so an embed limited to another type, or
    // pinned to one item, never lists them. Without Pro the route doesn't
    // exist — any failure just means "no services".
    if (!props.config.bookableItemId && (!props.config.type || props.config.type === 'appointment')) {
      services.value = await getPublicServices().catch(() => []);
    }

    let target = null;
    if (props.config.bookableItemId) {
      target = items.value.find((i) => i.id === props.config.bookableItemId) || null;
    } else if (items.value.length === 1 && services.value.length === 0) {
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
