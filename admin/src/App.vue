<template>
  <div class="byzo-app">
    <router-view />
  </div>
</template>

<script setup>
// Root shell. Deliberately minimal in Phase 0 — the persistent nav
// (ByzoPageHeader per route, sidebar/tabs) lands with the first real
// screens in Phase 1, not here, so this component doesn't need reworking
// once that structure is known.
</script>

<style>
@import './styles/tokens.css';

.byzo-app {
  color: var(--byzo-text);
  font-size: 14px;
}
</style>
