<template>
  <!-- An optional page about the Pro add-on, shown only while Pro isn't
       active (byzo_show_get_pro()). It describes what Pro adds; it never
       shows anything here as locked or held back, and "Continue" moves on
       exactly as every other step does (bookyzo-dev skill, Guideline 5). -->
  <Card>
    <CardHeader>
      <span class="mb-1 inline-flex w-fit items-center rounded-full bg-accent px-2.5 py-0.5 text-xs font-medium text-primary">{{ __('Optional', 'bookyzo') }}</span>
      <CardTitle>{{ __('Do more with Bookyzo Pro', 'bookyzo') }}</CardTitle>
      <CardDescription>{{ __('Pro is a separate add-on for when your business needs more. Everything you just set up keeps working as it is.', 'bookyzo') }}</CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col gap-5">
      <ul class="grid gap-3 sm:grid-cols-2">
        <li v-for="feature in features" :key="feature.title" class="flex items-start gap-3 rounded-lg border border-border p-3">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-accent text-primary">
            <component :is="feature.icon" class="h-4 w-4" />
          </span>
          <span class="min-w-0">
            <span class="block text-sm font-medium leading-5 text-foreground">{{ feature.title }}</span>
            <span class="block text-xs text-muted-foreground">{{ feature.text }}</span>
          </span>
        </li>
      </ul>

      <!-- Already bought and installed, just switched off: offer that,
           not a second purchase. -->
      <div v-if="activateProUrl" class="flex flex-col gap-3 rounded-lg border border-border bg-muted/40 p-4 sm:flex-row sm:items-center sm:justify-between">
        <span class="text-sm text-foreground">{{ __('Bookyzo Pro is installed on this site but not active yet.', 'bookyzo') }}</span>
        <a :href="activateProUrl" class="shrink-0">
          <Button>{{ __('Activate Bookyzo Pro', 'bookyzo') }}</Button>
        </a>
      </div>
      <div v-else-if="getProUrl" class="flex flex-col gap-3 rounded-lg border border-border bg-muted/40 p-4 sm:flex-row sm:items-center sm:justify-between">
        <span class="text-sm text-foreground">{{ __('Bought it already? Install Bookyzo Pro, then enter your license key under Bookyzo → Activate License.', 'bookyzo') }}</span>
        <a :href="getProUrl" target="_blank" rel="noopener noreferrer" class="shrink-0">
          <Button>
            {{ __('Get Bookyzo Pro', 'bookyzo') }}
            <ExternalLink class="ml-1.5 h-3.5 w-3.5" />
          </Button>
        </a>
      </div>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">{{ __('Back', 'bookyzo') }}</Button>
      <Button variant="outline" @click="$emit('next')">{{ __('Continue with Free', 'bookyzo') }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
import { BarChart3, CalendarSync, ExternalLink, MessageSquare, Repeat, Tag, Ticket } from 'lucide-vue-next';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

defineEmits(['next', 'back']);

const getProUrl = (window.byzoAdmin && window.byzoAdmin.getProUrl) || '';
const activateProUrl = (window.byzoAdmin && window.byzoAdmin.activateProUrl) || '';

// The headline Pro features, same set as GetPro.vue's comparison table.
const features = [
  { icon: MessageSquare, title: i18n.__('SMS & WhatsApp reminders', 'bookyzo'), text: i18n.__('Fewer no-shows, sent on your own schedule.', 'bookyzo') },
  { icon: Tag, title: i18n.__('Deposits, coupons & packages', 'bookyzo'), text: i18n.__('Take part-payments, run offers, sell credit packs.', 'bookyzo') },
  { icon: CalendarSync, title: i18n.__('Google & Outlook sync', 'bookyzo'), text: i18n.__('Two-way: bookings go in, busy times block slots.', 'bookyzo') },
  { icon: BarChart3, title: i18n.__('Analytics & reports', 'bookyzo'), text: i18n.__('Revenue, no-shows and your busiest hours.', 'bookyzo') },
  { icon: Repeat, title: i18n.__('Services & recurring bookings', 'bookyzo'), text: i18n.__('"Any available staff" and weekly regulars.', 'bookyzo') },
  { icon: Ticket, title: i18n.__('Floor plan, tickets & check-in', 'bookyzo'), text: i18n.__('Tools for restaurants and events.', 'bookyzo') },
];
</script>
