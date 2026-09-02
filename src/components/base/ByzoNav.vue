<template>
  <nav class="flex gap-1 border-b border-border px-6">
    <router-link
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="border-b-2 border-transparent px-3 py-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
      active-class="border-primary text-primary"
    >
      {{ item.label }}
    </router-link>
  </nav>
</template>

<script setup>
// Left-to-right tab nav rather than a sidebar — matches the bookyzo-design
// skill's "feels like a well-designed part of wp-admin" goal better than
// a second sidebar competing with WP's own admin menu. Grows as Phase 1
// step 9 adds Settings.
const items = [
  { to: '/calendar', label: 'Calendar' },
  { to: '/bookings', label: 'Bookings' },
  { to: '/resources', label: 'Resources' },
];
</script>
