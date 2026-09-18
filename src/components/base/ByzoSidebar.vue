<template>
  <div class="byzo-sidebar-slot flex-shrink-0" aria-hidden="true"></div>
  <aside class="byzo-sidebar flex flex-col border-r" style="background: var(--byzo-surface); border-color: var(--byzo-border)">
    <div class="flex items-center gap-3 border-b px-5 py-5" style="border-color: var(--byzo-border)">
      <div
        class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-white"
        style="background: linear-gradient(135deg, var(--byzo-primary), var(--byzo-primary-dark))"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <path d="M16 2v4M8 2v4M3 10h18" />
        </svg>
      </div>
      <div class="min-w-0">
        <strong class="block truncate text-sm font-bold leading-tight">Bookyzo</strong>
        <span class="block text-xs" style="color: var(--byzo-text-muted)">Bookings</span>
      </div>
    </div>

    <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto p-3">
      <router-link
        v-for="item in allItems"
        :key="item.to"
        :to="item.to"
        class="byzo-sidebar-item flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
        :class="{ 'byzo-sidebar-item--accent': item.accent }"
        active-class="byzo-sidebar-item--active"
      >
        <span class="flex h-5 w-5 flex-shrink-0 items-center justify-center" v-html="item.icon"></span>
        {{ item.label }}
      </router-link>
    </nav>

    <div class="border-t px-4 py-3 text-xs" style="border-color: var(--byzo-border); color: var(--byzo-text-muted)">
      Bookyzo v1.0.0
    </div>
  </aside>
</template>

<script setup>
// Bookyzo's own left nav — a full sidebar (not the earlier tab-row
// design) sitting beside wp-admin's own menu, per an explicit request to
// give this admin app a standalone sidebar shell rather than living
// inside wp-admin's tab conventions. Icons are inline SVG strings (kept
// tiny and static) rather than a component-per-icon, since this list is
// short and unlikely to grow quickly.
const icon = (paths) => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${paths}</svg>`;

// The sidebar is `position: fixed` (see <style> below) so it stays put
// regardless of *why* wp-admin's page is taller than the viewport — most
// commonly wp-admin's own left menu (grown tall by other installed
// plugins) forces #wpwrap taller than our own content, which a
// `position: sticky` sidebar can't survive since its containing block
// (this component's own flex row) is only ever as tall as our content.
// Fixed positioning needs its top/left measured from the live DOM instead
// of guessed constants, since wp-admin's admin-bar height and menu width
// vary (mobile admin bar, folded/collapsed menu, RTL, third-party admin
// themes) and shift live via CSS-transitioned classes on <body> rather
// than a JS event we could subscribe to.
import { computed, onMounted, onBeforeUnmount } from 'vue';
import { extraNavItems } from '../../lib/admin-extensions';

let resizeHandler = null;
let bodyClassObserver = null;
let settleTimer = null;

function syncFixedOffsets() {
  const adminBar = document.getElementById('wpadminbar');
  const wpContent = document.getElementById('wpcontent');
  const top = adminBar ? adminBar.getBoundingClientRect().bottom : 32;
  const left = wpContent ? wpContent.getBoundingClientRect().left : 0;
  const root = document.documentElement.style;
  root.setProperty('--byzo-sidebar-top', `${Math.max(top, 0)}px`);
  root.setProperty('--byzo-sidebar-left', `${Math.max(left, 0)}px`);
}

onMounted(() => {
  syncFixedOffsets();

  resizeHandler = () => syncFixedOffsets();
  window.addEventListener('resize', resizeHandler);

  // wp-admin toggles classes on <body> (folded menu, mobile nav open) to
  // resize/reposition its own left menu; re-measure whenever that happens
  // instead of duplicating wp-admin's own breakpoints here. The fold is a
  // CSS transition, not instant, so re-measure again once it settles.
  bodyClassObserver = new MutationObserver(() => {
    syncFixedOffsets();
    clearTimeout(settleTimer);
    settleTimer = setTimeout(syncFixedOffsets, 350);
  });
  bodyClassObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
});

onBeforeUnmount(() => {
  if (resizeHandler) window.removeEventListener('resize', resizeHandler);
  bodyClassObserver?.disconnect();
  clearTimeout(settleTimer);
});

const items = [
  { to: '/', label: 'Dashboard', icon: icon('<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>') },
  { to: '/calendar', label: 'Calendar', icon: icon('<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>') },
  { to: '/bookings', label: 'Bookings', icon: icon('<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>') },
  { to: '/bookable-items', label: 'Bookable Items', icon: icon('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') },
  { to: '/customers', label: 'Customers', icon: icon('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') },
  { to: '/settings/email-templates', label: 'Emails', icon: icon('<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>') },
  { to: '/settings/payments', label: 'Payments', icon: icon('<rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/>') },
  { to: '/settings/appearance', label: 'Appearance', icon: icon('<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>') },
  { to: '/settings/general', label: 'Settings', icon: icon('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>') },
];

// A real, separate "Get Pro" page — never a locked/disabled preview of an
// actual feature, see GetPro.vue's own docblock and the bookyzo-design
// skill's explicit rule on this. Shown whenever we're not CONFIRMED
// licensed (proLicensed defaults to false/shown if the flag is somehow
// missing, since hiding a legitimate free user's upgrade path is worse
// than one extra link for an already-licensed site) — PHP only registers
// the WP admin page + Vue route it points to under that same condition
// (BYZO_Plugin::routes()), so this can never link to a 404.
const proLicensed = !!(window.byzoAdmin && window.byzoAdmin.proLicensed);
const getProItem = {
  to: '/get-pro',
  label: 'Get Pro',
  icon: icon('<path d="m12 15 3.5-3.5L12 8"/><path d="M4.93 4.93a10 10 0 1 0 14.14 14.14A10 10 0 0 0 4.93 4.93Z"/>'),
  accent: true,
};

// Pro's own bundle pushes its nav items (e.g. License) onto extraNavItems
// via window.byzoAdmin.registerExtension — see lib/admin-extensions.js.
// Rendered after Free's own items; "Get Pro" goes last of all so it never
// gets mistaken for one of Pro's own screens.
const allItems = computed(() => [...items, ...extraNavItems, ...(proLicensed ? [] : [getProItem])]);
</script>

<style scoped>
/* Invisible flex spacer that reserves the sidebar's width in the shell's
   layout, since the real `.byzo-sidebar` below is taken out of flow by
   `position: fixed` and can no longer do that itself. */
.byzo-sidebar-slot {
  width: var(--byzo-sidebar-width, 240px);
}

.byzo-sidebar {
  position: fixed;
  top: var(--byzo-sidebar-top, 32px);
  left: var(--byzo-sidebar-left, 160px);
  width: var(--byzo-sidebar-width, 240px);
  height: calc(100vh - var(--byzo-sidebar-top, 32px));
  z-index: 20;
}

.byzo-sidebar-item {
  color: var(--byzo-text-muted);
}
.byzo-sidebar-item:hover {
  background: var(--byzo-surface-alt);
  color: var(--byzo-text);
}
.byzo-sidebar-item--active {
  background: var(--byzo-primary-light);
  color: var(--byzo-primary-dark);
  font-weight: 600;
}

/* Stays red/bold even while active (declared after --active so it wins
   on the shared `color`/`font-weight` properties) — a call-to-action row,
   not just another nav destination, same "Get Pro" treatment this
   author's other Codeholt plugins use. */
.byzo-sidebar-item--accent {
  color: var(--byzo-danger);
  font-weight: 700;
}
.byzo-sidebar-item--accent:hover {
  color: var(--byzo-danger);
  background: var(--byzo-danger-bg);
}

/*
 * Narrow screens: a 240px fixed rail leaves ~150px of content on a phone,
 * which is what turned every list row into a column of clipped single
 * letters. Below this width the rail stops being a rail — it un-fixes,
 * spans the full width, and its nav becomes one horizontally scrollable
 * strip of pills above the content, which is the shape wp-admin's own
 * narrow layouts use and needs no toggle state to manage.
 *
 * The breakpoint is content-driven (240px rail + a usable content column),
 * not wp-admin's own 782px — the layout is already cramped well before
 * wp-admin decides to fold its menu.
 */
@media screen and (max-width: 960px) {
  .byzo-sidebar-slot {
    display: none;
  }

  .byzo-sidebar {
    position: static;
    width: 100%;
    height: auto;
    border-right: 0;
    border-bottom: 1px solid var(--byzo-border);
  }

  /* The brand block is a wayfinding aid for a tall rail; in a horizontal
     strip it costs a whole row to repeat what the WP menu already says. */
  .byzo-sidebar > :first-child,
  .byzo-sidebar > :last-child {
    display: none;
  }

  .byzo-sidebar nav {
    flex-direction: row;
    flex-wrap: nowrap;
    gap: 0.25rem;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 0.5rem 0.75rem;
    /* Momentum scrolling without a visible bar eating vertical space. */
    scrollbar-width: none;
  }

  .byzo-sidebar nav::-webkit-scrollbar {
    display: none;
  }

  .byzo-sidebar-item {
    flex: 0 0 auto;
    white-space: nowrap;
  }
}
</style>
