<template>
  <div>
    <ByzoPageHeader :title="__('Get Pro', 'bookyzo')" :subtitle="__('More ways to grow your bookings, in the Bookyzo Pro add-on.', 'bookyzo')">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="m12 15 3.5-3.5L12 8" />
          <path d="M4.93 4.93a10 10 0 1 0 14.14 14.14A10 10 0 0 0 4.93 4.93Z" />
        </svg>
      </template>
      <template #actions>
        <a v-if="activateProUrl" :href="activateProUrl">
          <Button>{{ __('Activate Bookyzo Pro', 'bookyzo') }}</Button>
        </a>
        <a v-else-if="getProUrl" :href="getProUrl" target="_blank" rel="noopener noreferrer">
          <Button style="background: var(--byzo-danger); border-color: var(--byzo-danger)">
            {{ __('Get Bookyzo Pro', 'bookyzo') }}
            <ExternalLink class="ml-1.5 h-3.5 w-3.5" />
          </Button>
        </a>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8 flex flex-col gap-4">
      <!-- Installed but switched off → activate it; otherwise how a bought
           license gets onto this site. -->
      <div class="rounded-lg border px-4 py-3 text-sm flex flex-wrap items-center justify-between gap-4" style="border-color: var(--byzo-border); background: var(--byzo-surface-alt); color: var(--byzo-text-muted)">
        <span v-if="activateProUrl">
          {{ __('Bookyzo Pro is installed on this site but not active. Activate it, then enter your license key under Bookyzo → Activate License.', 'bookyzo') }}
        </span>
        <span v-else>
          {{ __('Bookyzo Pro is a separate add-on. If you already have a license key, install and activate the Bookyzo Pro plugin, then enter the key under Bookyzo → Activate License.', 'bookyzo') }}
        </span>
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
        {{ __('Every booking type (Appointment, Table, Event, Slot/Class) is fully included in the free plugin — as are unlimited bookable items and shifts. Pro adds more features, never a locked booking type.', 'bookyzo') }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
// A real, separate comparison page — not a dimmed/locked preview of any
// actual Pro screen — per the bookyzo-design skill's explicit rule (mirrors
// license-envato's own Get Pro page). Only reachable at all while
// byzo_show_get_pro() is true (see BYZO_Plugin::routes()/
// ByzoSidebar.vue) — the row/route disappears entirely once Pro is
// active rather than sticking around as a dead tab.
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ExternalLink } from 'lucide-vue-next';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';

const getProUrl = window.byzoAdmin?.getProUrl || '';
const activateProUrl = window.byzoAdmin?.activateProUrl || '';

// Summarized from FEATURES.md Part B — not a line-by-line dump of every
// bullet there, just the rows a site owner actually weighs when deciding
// to upgrade. Keep this in sync if FEATURES.md's B1-B11 headline items
// change materially. Checked against what bookyzo-pro actually ships on
// 2026-10-31: every row here exists there, and nothing here is in Free.
const rows = [
  { label: i18n.__('Services with per-staff pricing & "any available staff" booking', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Multiple locations, each with its own timezone and hours', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Staff logins that see only their own bookings', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('SMS & WhatsApp messages, with your own reminder timings', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Email & SMS campaigns and post-visit follow-up emails', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Deposits and PayPal alongside Stripe', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Refunds on cancellation, approved by you', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Discount codes & prepaid packages', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Seasonal, weekday & lead-time pricing', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Add-ons, booking fees & per-item tax', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Waitlist for full times', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Recurring bookings', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Bulk actions, internal notes & tags', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Table floor plan, combined tables & walk-in waitlist', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Event ticket tiers, QR e-tickets, check-in scanner & attendee export', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Branded PDF invoices', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Analytics dashboard & CSV reports', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Commission & payout tracking', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Live alerts for new bookings, cancellations & no-shows', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Google Calendar & Outlook two-way sync', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('WooCommerce checkout', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Webhooks & marketing pixels', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Custom form fields', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Custom CSS for the booking form', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Customer list export with booking history', 'bookyzo'), free: '—', pro: '✓' },
  { label: i18n.__('Automatic Pro plugin updates', 'bookyzo'), free: '—', pro: '✓' },
];
</script>
