<template>
  <div class="byzo-app">
    <template v-if="route.name === 'setup'">
      <router-view />
    </template>
    <div v-else class="byzo-shell flex">
      <ByzoSidebar />
      <div class="flex-1 overflow-x-hidden">
        <router-view />
      </div>
    </div>
    <Toaster position="top-right" rich-colors />
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router';
import ByzoSidebar from '@/components/base/ByzoSidebar.vue';
import { Toaster } from '@/components/ui/sonner';

// Setup Wizard is deliberately full-screen (no persistent nav) — every
// other route gets Bookyzo's own sidebar, sitting beside wp-admin's own
// left menu rather than replacing/collapsing it.
const route = useRoute();
</script>

<style>
@import './styles/tailwind.css';

.byzo-app {
  color: var(--byzo-text);
  font-size: 14px;
}

/*
 * The shell fills whatever vertical space wp-admin leaves below the admin
 * bar, so short pages still look like they occupy the full viewport
 * instead of stopping at their own content. --byzo-shell-offset is the
 * admin bar (32px, 46px on mobile) plus the padding wp-admin puts above
 * #wpbody-content; `min-height` (not `height`) so long screens still grow
 * past the fold. The sidebar itself (ByzoSidebar.vue) is `position: fixed`
 * and ignores this — it measures its own top/left from the live DOM,
 * since wp-admin's own left menu (grown tall by other installed plugins)
 * routinely forces the page taller than our content here, which a
 * flex-stretched or sticky sidebar can't survive.
 */
.byzo-shell {
  --byzo-shell-offset: 62px;
  --byzo-sidebar-width: 240px;
  min-height: calc(100vh - var(--byzo-shell-offset));
  align-items: stretch;
}

@media screen and (max-width: 782px) {
  .byzo-shell {
    --byzo-shell-offset: 76px;
  }
}
</style>
