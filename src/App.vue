<template>
  <ConfigProvider teleport-to="#byzo-admin-app">
    <div class="byzo-app">
      <template v-if="route.name === 'setup'">
        <router-view />
      </template>
      <div v-else class="byzo-shell flex">
        <ByzoSidebar />
        <div class="byzo-shell-main min-w-0 flex-1">
          <router-view />
        </div>
      </div>
      <Toaster position="top-right" rich-colors />
    </div>
  </ConfigProvider>
</template>

<script setup>
import { useRoute } from 'vue-router';
import { ConfigProvider } from 'reka-ui';
import ByzoSidebar from '@/components/base/ByzoSidebar.vue';
import { Toaster } from '@/components/ui/sonner';

// Setup Wizard is deliberately full-screen (no persistent nav) — every
// other route gets Bookyzo's own sidebar, sitting beside wp-admin's own
// left menu rather than replacing/collapsing it.
const route = useRoute();

// Every Dialog/Select/Popover/DropdownMenu here is a reka-ui primitive
// built on its own <Teleport>, which with no `to` set defaults to
// `document.body` (Teleport.js: `props.to ?? configContext.teleportTo ??
// "body"`) — OUTSIDE #byzo-admin-app entirely. That's what silently broke
// every text input/textarea inside a Dialog (Add/Edit bookable item,
// Add/Edit customer): the wp-admin-chrome-reset rules in styles/
// tailwind.css are scoped `#byzo-admin-app input[...]` on purpose (it's
// the one element present on every Bookyzo route, unlike a body class),
// but content teleported to <body> sits outside that selector's reach, so
// wp-admin's own forms.css (hardcoded `border-radius: 2px` etc.) won it
// silently. This ConfigProvider is reka-ui's own mechanism for
// redirecting every descendant's Teleport target in one place — with it,
// portaled content mounts inside #byzo-admin-app instead of <body>, so
// those selectors reach it like anything else. Safe positioning-wise:
// neither #byzo-admin-app nor .byzo-app/.byzo-shell sets a transform/
// filter/contain that would turn them into a containing block, so each
// Dialog's `fixed inset-0` overlay still covers the full viewport exactly
// as it did teleported to <body>.
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
  /* Shared by the sidebar's brand block and the page header below, so
     their bottom borders line up. Border included. */
  --byzo-topbar-height: 80px;
  min-height: calc(100vh - var(--byzo-shell-offset));
  align-items: stretch;
}

/*
 * `clip`, not `hidden`: overflow-x: hidden silently turns overflow-y into
 * `auto`, making this column a scroll container that never scrolls — and
 * a sticky header inside it would then stick to that instead of the page.
 * `min-w-0` on the element keeps what `hidden` used to give for free (a
 * wide table can't stretch the column past the viewport).
 */
.byzo-shell-main {
  overflow-x: clip;
}

/*
 * The page header (Free's ByzoPageHeader, Pro's .byzop-header — every
 * top-level route starts with one of the two) stays pinned under the
 * admin bar like the sidebar, so only the screen's body scrolls. Only the
 * view root's direct child, so a header-styled block nested deeper in a
 * screen is left alone. From 783px up only: below that wp-admin switches
 * to its mobile layout, and under 600px the admin bar itself scrolls away,
 * which would leave a gap above a header pinned at its measured bottom.
 */
@media screen and (min-width: 783px) {
  .byzo-shell-main > * > .byzo-page-header,
  .byzo-shell-main > * > .byzop-header {
    position: sticky;
    top: var(--byzo-sidebar-top, 32px);
    z-index: 10;
    background: hsl(var(--background));
    border-bottom: 1px solid var(--byzo-border);
    /* Every screen body starts with no top padding (it leaned on the
       header's own py-5), so once the border sits under the header the
       first card would touch it without this gap. */
    margin-bottom: 1.5rem;
  }

  /* Height matches the sidebar's brand block (--byzo-topbar-height) so
     both borders sit on one line. min-height, not height: if a screen's
     actions ever wrap, the header grows instead of clipping them. The
     extra .byzo-shell raises specificity over Pro's scoped
     `.byzop-header` padding. */
  .byzo-shell .byzo-shell-main > * > .byzo-page-header,
  .byzo-shell .byzo-shell-main > * > .byzop-header {
    box-sizing: border-box;
    min-height: var(--byzo-topbar-height);
    padding-top: 0;
    padding-bottom: 0;
  }
}

/*
 * Matches ByzoSidebar.vue's own breakpoint: once the rail un-fixes and
 * becomes a horizontal strip, the shell has to stack it above the content
 * rather than keep them side by side in a flex row.
 */
@media screen and (max-width: 960px) {
  .byzo-shell {
    flex-direction: column;
  }
}

@media screen and (max-width: 782px) {
  .byzo-shell {
    --byzo-shell-offset: 76px;
  }
}
</style>
