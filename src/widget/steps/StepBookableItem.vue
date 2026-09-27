<template>
  <WidgetLoading v-if="loading" />
  <div v-else-if="error" class="error">{{ error }}</div>
  <div v-else-if="items.length === 0 && services.length === 0" class="empty">{{ __('No bookable services are available right now.', 'bookyzo') }}</div>
  <!-- Several locations (QA report §6.1 A1): where first, then what. -->
  <div v-else-if="needsLocation && !autoSkipping">
    <h2>{{ __('Choose a location', 'bookyzo') }}</h2>
    <div class="bookable-item-list">
      <button v-for="location in locations" :key="`location-${location.id}`" type="button" class="option-card" @click="chosenLocation = location">
        <span class="option-card-body">
          <span class="name">{{ location.name }}</span>
          <span v-if="location.address" class="meta"><span class="meta-item">{{ location.address }}</span></span>
        </span>
        <span class="option-card-go" aria-hidden="true">&#8250;</span>
      </button>
    </div>
  </div>
  <div v-else-if="!autoSkipping">
    <h2>{{ copy.pickHeading }}</h2>
    <p class="step-sub">
      {{ copy.pickSub }}
      <template v-if="chosenLocation">
        <br />{{ chosenLocation.name }} · <button type="button" class="link-button" @click="chosenLocation = null">{{ __('Change location', 'bookyzo') }}</button>
      </template>
    </p>
    <!-- Pro services (QA report 2026-09-22 B9): picked by what, not by
         whom — the next step offers the staff who provide it, or "any
         available". Absent when there are none (always, without Pro). -->
    <p v-if="visibleServices.length && visibleItems.length" class="group-label">{{ __('Services', 'bookyzo') }}</p>
    <div v-if="visibleServices.length" class="bookable-item-list">
      <button
        v-for="service in visibleServices"
        :key="`service-${service.id}`"
        type="button"
        class="option-card"
        @click="$emit('selected-service', service, visibleItems)"
      >
        <span class="option-card-body">
          <span class="name">{{ service.name }}</span>
          <span class="meta">
            <span class="meta-item">{{ sprintf(/*! translators: %s: duration in minutes. */ __('%s min', 'bookyzo'), serviceDuration(service)) }}</span>
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
              <span class="meta-item">{{ sprintf(/*! translators: %s: duration in minutes. */ __('%s min', 'bookyzo'), item.duration_minutes) }}</span>
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
import { i18n } from '@/lib/i18n';
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
  return prices.every((p) => p === low) ? formatMoney(low) : i18n.sprintf(/*! translators: %s: lowest price, formatted with its currency. */ i18n.__('from %s', 'bookyzo'), formatMoney(low));
}

// A picker filtered to one type (or a site that only sells one) can speak
// that type's language; a genuinely mixed list can't, so it stays neutral.
// Locations (QA report §6.1 A1) — only when an add-on lists more than one.
// Items not placed at any location are offered at all of them.
const locations = (window.byzoWidget && window.byzoWidget.locations) || [];
const chosenLocation = ref(null);
const needsLocation = computed(() => locations.length > 1 && !chosenLocation.value && !props.config.bookableItemId);
// An event with no date left to sell would open onto an empty date
// picker, so it isn't offered (a link pinned to it still opens it).
const visibleItems = computed(() =>
  items.value.filter(
    (i) =>
      i.has_upcoming_dates !== false &&
      (!chosenLocation.value || !i.location_id || i.location_id === chosenLocation.value.id)
  )
);
const visibleServices = computed(() => {
  if (!chosenLocation.value) return services.value;
  const ids = new Set(visibleItems.value.map((i) => i.id));
  return services.value.map((s) => ({ ...s, staff: s.staff.filter((m) => ids.has(m.id)) })).filter((s) => s.staff.length);
});

const copy = computed(() => {
  const types = new Set(visibleItems.value.map((i) => i.type));
  return types.size === 1 && !visibleServices.value.length ? copyFor(visibleItems.value[0].type) : MIXED_COPY;
});

// One group per type, in a fixed order, each keeping the server's own
// ordering inside it.
const groups = computed(() =>
  GROUP_ORDER.map((type) => ({
    type,
    // With Pro services listed above, "Services" twice would be two
    // headings for two different things — the staff themselves come second.
    label: 'appointment' === type && visibleServices.value.length ? i18n.__('Or book with', 'bookyzo') : GROUP_LABELS[type],
    items: visibleItems.value.filter((item) => item.type === type),
  })).filter((group) => group.items.length)
);

// How many a table seats, a class takes, an event sells — in that type's
// own words rather than one generic "party size".
// One return per type, not an object of labels: the build keeps each
// translator note on the line above its own call only when every call
// starts its own statement (vite.translator-comments.js).
function capacityLabel(item) {
  if (item.type === 'table') {
    /*! translators: %s: number of seats at the table. */
    return i18n.sprintf(i18n.__('Seats %s', 'bookyzo'), item.capacity);
  }
  if (item.type === 'slot') {
    /*! translators: %s: number of spots. */
    return i18n.sprintf(i18n._n('%s spot', '%s spots', item.capacity, 'bookyzo'), item.capacity);
  }
  if (item.type === 'event') {
    /*! translators: %s: number of tickets. */
    return i18n.sprintf(i18n._n('%s ticket', '%s tickets', item.capacity, 'bookyzo'), item.capacity);
  }
  return '';
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
    const linked = props.config.linkedItemId && !props.config.bookableItemId ? items.value.find((i) => i.id === props.config.linkedItemId) : null;
    if (linked) {
      // Opened from a link to one time: go straight to it, but keep the
      // list one Back away.
      emit('selected', linked, false);
      return;
    }
    if (props.config.bookableItemId) {
      target = items.value.find((i) => i.id === props.config.bookableItemId) || null;
    } else if (items.value.length === 1 && services.value.length === 0 && locations.length <= 1) {
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
