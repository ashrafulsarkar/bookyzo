<template>
  <aside class="byzo-sidebar flex w-60 flex-shrink-0 flex-col self-stretch border-r" style="background: var(--byzo-surface); border-color: var(--byzo-border)">
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
        v-for="item in items"
        :key="item.to"
        :to="item.to"
        class="byzo-sidebar-item flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
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

const items = [
  { to: '/', label: 'Dashboard', icon: icon('<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>') },
  { to: '/calendar', label: 'Calendar', icon: icon('<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>') },
  { to: '/bookings', label: 'Bookings', icon: icon('<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>') },
  { to: '/bookable-items', label: 'Bookable Items', icon: icon('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') },
  { to: '/settings/email-templates', label: 'Emails', icon: icon('<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>') },
  { to: '/settings/payments', label: 'Payments', icon: icon('<rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/>') },
];
</script>

<style scoped>
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
</style>
