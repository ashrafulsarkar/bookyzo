<template>
  <span
    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
    :style="{ color, backgroundColor: bg }"
  >
    <slot />
  </span>
</template>

<script setup>
import { computed } from 'vue';

// bookyzo-design skill's ByzoBadge: status pills using the shared color
// tokens via a `status` prop, never ad hoc colors per usage site. Covers
// both resource statuses (active/inactive/archived) and — once the
// bookings screens land in later Phase 1 steps — booking statuses
// (pending/confirmed/completed/cancelled/no_show) via the same map.
const props = defineProps({
  status: { type: String, required: true },
});

const palette = {
  active: { color: 'var(--byzo-success)', bg: 'var(--byzo-success-bg)' },
  inactive: { color: 'var(--byzo-text-muted)', bg: 'var(--byzo-surface-alt)' },
  archived: { color: 'var(--byzo-text-muted)', bg: 'var(--byzo-surface-alt)' },
  pending: { color: 'var(--byzo-status-pending)', bg: 'var(--byzo-warning-bg)' },
  confirmed: { color: 'var(--byzo-status-confirmed)', bg: 'var(--byzo-primary-light)' },
  completed: { color: 'var(--byzo-status-completed)', bg: 'var(--byzo-success-bg)' },
  cancelled: { color: 'var(--byzo-status-cancelled)', bg: 'var(--byzo-surface-alt)' },
  no_show: { color: 'var(--byzo-status-no-show)', bg: 'var(--byzo-danger-bg)' },
};

const color = computed(() => palette[props.status]?.color ?? 'var(--byzo-text-muted)');
const bg = computed(() => palette[props.status]?.bg ?? 'var(--byzo-surface-alt)');
</script>
