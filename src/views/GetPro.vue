<template>
  <div>
    <ByzoPageHeader :title="__('Get Pro', 'bookyzo')" :subtitle="__('Unlimited bookable items and every growth feature, in the Bookyzo Pro add-on.', 'bookyzo')">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="m12 15 3.5-3.5L12 8" />
          <path d="M4.93 4.93a10 10 0 1 0 14.14 14.14A10 10 0 0 0 4.93 4.93Z" />
        </svg>
      </template>
      <template #actions>
        <a v-if="getProUrl" :href="getProUrl" target="_blank" rel="noopener noreferrer">
          <Button style="background: var(--byzo-danger); border-color: var(--byzo-danger)">{{ __('Get Bookyzo Pro', 'bookyzo') }}</Button>
        </a>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8 flex flex-col gap-4">
      <!-- NOTE: placeholder href="#" below — wire it to the real purchase
           URL (byzo_get_pro_url()) before this page is shown again; the
           whole page is hidden for now, see byzo_show_get_pro(). -->
      <div v-if="!getProUrl" class="rounded-lg border px-4 py-3 text-sm flex items-center justify-between gap-4" style="border-color: var(--byzo-border); background: var(--byzo-surface-alt); color: var(--byzo-text-muted)">
        <span>
          {{ __('Bookyzo Pro is a separate add-on. If you already have a license key, install and activate the Bookyzo Pro plugin, then enter the key under Bookyzo → License.', 'bookyzo') }}
        </span>
        <a href="#" class="shrink-0">
          <Button style="background: var(--byzo-danger); border-color: var(--byzo-danger)">{{ __('Purchase Bookyzo Pro', 'bookyzo') }}</Button>
        </a>
      </div>

      <Card>
        <CardContent class="p-0">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b" style="border-color: var(--byzo-border)">
                <th class="text-left font-medium px-4 py-3 text-muted-foreground">{{ __('Feature', 'bookyzo') }}</th>
                <th class="text-center font-medium px-4 py-3 w-28 text-muted-foreground">{{ __('Free', 'bookyzo') }}</th>
                <th class="text-center font-medium px-4 py-3 w-28" style="color: var(--byzo-primary)">{{ __('Pro', 'bookyzo') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.label" class="border-b last:border-b-0" style="border-color: var(--byzo-border)">
                <td class="px-4 py-3">{{ row.label }}</td>
                <td class="px-4 py-3 text-center text-muted-foreground">{{ row.free }}</td>
                <td class="px-4 py-3 text-center font-medium" style="color: var(--byzo-text)">{{ row.pro }}</td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>

      <p class="text-xs text-muted-foreground">
        {{ __('Every booking type (Appointment, Table, Event, Slot/Class) is fully included in the free plugin — Pro adds depth and volume, never a locked booking type.', 'bookyzo') }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
// A real, separate comparison page — not a dimmed/locked preview of any
// actual Pro screen — per the bookyzo-design skill's explicit rule (mirrors
// license-envato's own Get Pro page). Only reachable at all while
// byzo_is_pro_licensed() is false (see BYZO_Plugin::routes()/
// ByzoSidebar.vue) — the row/route disappears entirely once licensed
// rather than sticking around as a dead tab.
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';

const getProUrl = window.byzoAdmin?.getProUrl || '';

// Summarized from FEATURES.md Part B — not a line-by-line dump of every
// bullet there, just the rows a site owner actually weighs when deciding
// to upgrade. Keep this in sync if FEATURES.md's B1-B11 headline items
// change materially.
const rows = [
  { label: i18n.__('Bookable items per type (staff, tables, events, classes)', 'bookyzo'), free: '1', pro: i18n.__('Unlimited', 'bookyzo') },
  { label: i18n.__('Shifts per day', 'bookyzo'), free: '1', pro: i18n.__('Multiple', 'bookyzo') },
  { label: i18n.__('Multi-location / multi-branch scheduling', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('SMS & WhatsApp reminders', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Deposits & multiple payment gateways', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Coupons, package credits & dynamic pricing', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Bulk actions, notes & tags', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Recurring class series', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Table floor plan editor & walk-in waitlist', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Event ticket tiers, QR e-tickets & check-in scanner', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Analytics dashboard & report exports', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Staff calendar sync (Google / Outlook)', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Webhooks, marketing pixels & calendar feeds', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Custom form field builder', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Custom CSS for the booking widget', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Automatic Pro plugin updates', 'bookyzo'), free: '—', pro: '✓' },
];
</script>
