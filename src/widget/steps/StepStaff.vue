<template>
  <div>
    <h2>{{ __('Choose who you\'d like', 'bookyzo') }}</h2>
    <p class="step-sub">{{ service.name }}</p>
    <div class="bookable-item-list">
      <!-- Listed first: most customers don't mind who, and this shows the
           most open times (every staff member's, merged). -->
      <button v-if="service.staff.length > 1" type="button" class="option-card" @click="chooseAny">
        <span class="option-card-body">
          <span class="name">{{ __('Any available', 'bookyzo') }}</span>
          <span class="meta">
            <span class="meta-item">{{ sprintf(/*! translators: %s: number of staff members who offer this service. */ __('Whoever is free — any of %s', 'bookyzo'), service.staff.length) }}</span>
            <span v-if="anyPriceLabel" class="meta-price">{{ anyPriceLabel }}</span>
          </span>
        </span>
        <span class="option-card-go" aria-hidden="true">&#8250;</span>
      </button>
      <button v-for="member in service.staff" :key="member.id" type="button" class="option-card" @click="chooseStaff(member)">
        <span class="option-card-body">
          <span class="name">{{ member.name }}</span>
          <span class="meta">
            <span class="meta-item">{{ sprintf(/*! translators: %s: duration in minutes. */ __('%s min', 'bookyzo'), member.duration_minutes) }}</span>
            <span v-if="member.price !== null" class="meta-price">{{ formatMoney(member.price) }}</span>
          </span>
        </span>
        <span class="option-card-go" aria-hidden="true">&#8250;</span>
      </button>
    </div>
    <div class="actions">
      <button type="button" class="btn btn-outline" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</button>
    </div>
  </div>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
// "Service → staff (or any available)" — the step between picking a Pro
// service and picking a time (DETAILED-SPEC.md §3 B7, QA report 2026-09-22
// B9). Emits a bookable-item-shaped object so the steps after it work
// unchanged: `service_id` is always set, `staff_id` is the chosen staff
// member's bookable item id, or null for "any available" (slots then come
// from /slots?service_id=, and the server assigns the staff at booking).
import { computed } from 'vue';
import { formatMoney } from '@/lib/money';

const props = defineProps({
  service: { type: Object, required: true },
  // The public bookable-item list the first step already loaded — the
  // staff members' own rows carry location/meeting link for later steps.
  items: { type: Array, default: () => [] },
});
const emit = defineEmits(['selected', 'back']);

const prices = computed(() => props.service.staff.map((m) => m.price).filter((p) => p !== null));

// One price when everyone charges the same; "from" the lowest otherwise.
const anyPriceLabel = computed(() => {
  if (!prices.value.length) return '';
  const low = Math.min(...prices.value);
  return prices.value.every((p) => p === low) ? formatMoney(low) : i18n.sprintf(/*! translators: %s: lowest price, formatted with its currency. */ i18n.__('from %s', 'bookyzo'), formatMoney(low));
});

function chooseStaff(member) {
  const own = props.items.find((i) => i.id === member.id) || {};
  emit('selected', {
    ...own,
    id: member.id,
    type: 'appointment',
    name: i18n.sprintf(/*! translators: 1: service name, 2: staff member name. */ i18n.__('%1$s with %2$s', 'bookyzo'), props.service.name, member.name),
    price: member.price,
    duration_minutes: member.duration_minutes,
    service_id: props.service.id,
    service_name: props.service.name,
    staff_id: member.id,
    staff: props.service.staff,
  });
}

function chooseAny() {
  const durations = props.service.staff.map((m) => m.duration_minutes);
  emit('selected', {
    id: null,
    type: 'appointment',
    name: props.service.name,
    price: prices.value.length ? Math.min(...prices.value) : null,
    duration_minutes: Math.min(...durations),
    service_id: props.service.id,
    service_name: props.service.name,
    staff_id: null,
    staff: props.service.staff,
  });
}
</script>
