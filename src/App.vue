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
 * bar, so the sidebar runs the full height of the page instead of stopping
 * at its own content. --byzo-shell-offset is the admin bar (32px, 46px on
 * mobile) plus the padding wp-admin puts above #wpbody-content; `min-height`
 * (not `height`) so long screens still grow past the fold.
 */
.byzo-shell {
  --byzo-shell-offset: 62px;
  min-height: calc(100vh - var(--byzo-shell-offset));
  align-items: stretch;
}

@media screen and (max-width: 782px) {
  .byzo-shell {
    --byzo-shell-offset: 76px;
  }
}
</style>
