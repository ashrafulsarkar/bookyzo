<template>
  <div>
    <ByzoPageHeader title="Get Pro" subtitle="Unlock unlimited bookable items and every growth feature.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="m12 15 3.5-3.5L12 8" />
          <path d="M4.93 4.93a10 10 0 1 0 14.14 14.14A10 10 0 0 0 4.93 4.93Z" />
        </svg>
      </template>
      <template #actions>
        <a v-if="getProUrl" :href="getProUrl" target="_blank" rel="noopener noreferrer">
          <Button style="background: var(--byzo-danger); border-color: var(--byzo-danger)">Get Bookyzo Pro</Button>
        </a>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8 flex flex-col gap-4">
      <!-- NOTE: placeholder href="#" below — must be wired to a real purchase
           URL (or removed again) before any WP.org submission; a dead link
           here is a Guideline 5 (Trialware) rejection risk, see bookyzo-dev skill. -->
      <div v-if="!getProUrl" class="rounded-lg border px-4 py-3 text-sm flex items-center justify-between gap-4" style="border-color: var(--byzo-border); background: var(--byzo-surface-alt); color: var(--byzo-text-muted)">
        <span>
          Unlock unlimited bookable items and every growth feature with Bookyzo Pro. If you already have a
          license key, install &amp; activate the Bookyzo Pro plugin, then enter it under Bookyzo → License.
        </span>
        <a href="#" class="shrink-0">
          <Button style="background: var(--byzo-danger); border-color: var(--byzo-danger)">Purchase Bookyzo Pro</Button>
        </a>
      </div>

      <Card>
        <CardContent class="p-0">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b" style="border-color: var(--byzo-border)">
                <th class="text-left font-medium px-4 py-3 text-muted-foreground">Feature</th>
                <th class="text-center font-medium px-4 py-3 w-28 text-muted-foreground">Free</th>
                <th class="text-center font-medium px-4 py-3 w-28" style="color: var(--byzo-primary)">Pro</th>
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
        Every booking type (Appointment, Table, Event, Slot/Class) is fully included in the free plugin — Pro adds
        depth and volume, never a locked booking type.
      </p>
    </div>
  </div>
</template>

<script setup>
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
  { label: 'Bookable items per type (staff, tables, events, classes)', free: '1', pro: 'Unlimited' },
  { label: 'Shifts per day', free: '1', pro: 'Multiple' },
  { label: 'Multi-location / multi-branch scheduling', free: '—', pro: '✓' },
  { label: 'SMS & WhatsApp reminders', free: '—', pro: '✓' },
  { label: 'Deposits & multiple payment gateways', free: '—', pro: '✓' },
  { label: 'Coupons, package credits & dynamic pricing', free: '—', pro: '✓' },
  { label: 'Bulk actions, notes & tags', free: '—', pro: '✓' },
  { label: 'Recurring class series', free: '—', pro: '✓' },
  { label: 'Table floor plan editor & walk-in waitlist', free: '—', pro: '✓' },
  { label: 'Event ticket tiers, QR e-tickets & check-in scanner', free: '—', pro: '✓' },
  { label: 'Analytics dashboard & CSV exports', free: '—', pro: '✓' },
  { label: 'Staff calendar sync (Google / Outlook)', free: '—', pro: '✓' },
  { label: 'Webhooks, marketing pixels & calendar feeds', free: '—', pro: '✓' },
  { label: 'Custom form field builder', free: '—', pro: '✓' },
  { label: 'Custom CSS for the booking widget', free: '—', pro: '✓' },
  { label: 'Automatic Pro plugin updates', free: '—', pro: '✓' },
];
</script>
