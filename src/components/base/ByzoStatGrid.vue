<template>
  <div class="byzo-stat-grid grid gap-4">
    <slot />
  </div>
</template>

<script setup>
// bookyzo-design skill's ByzoStatGrid: 4-up grid that stays one row down
// to tablet widths, then steps down so cards never overflow narrow
// wp-admin viewports.
</script>

<style scoped>
.byzo-stat-grid {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

@media (max-width: 780px) {
  .byzo-stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 420px) {
  .byzo-stat-grid {
    grid-template-columns: 1fr;
  }
}
</style>
