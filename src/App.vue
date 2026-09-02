<template>
  <div class="byzo-app">
    <ByzoNav v-if="route.name !== 'setup'" />
    <router-view />
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router';
import ByzoNav from '@/components/base/ByzoNav.vue';

// Setup Wizard is deliberately full-screen (no persistent nav) — every
// other route gets the tab nav. Grows into a fuller shell (breadcrumbs,
// global search, etc.) only once there's enough screens to need one.
const route = useRoute();
</script>

<style>
@import './styles/tailwind.css';

.byzo-app {
  color: var(--byzo-text);
  font-size: 14px;
}
</style>
