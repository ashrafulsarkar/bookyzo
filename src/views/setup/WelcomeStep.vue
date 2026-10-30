<template>
  <!-- The first screen a new install lands on, so it says what the next
       couple of minutes produce instead of a one-line card with a button. -->
  <Card>
    <CardContent class="flex flex-col items-center gap-6 px-6 pb-8 pt-10 text-center sm:px-10">
      <div
        class="flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-sm"
        style="background: linear-gradient(135deg, var(--byzo-primary), var(--byzo-primary-dark))"
      >
        <CalendarCheck class="h-7 w-7" />
      </div>

      <div class="flex flex-col gap-2">
        <h2 class="text-xl font-semibold text-foreground">{{ __('Welcome to Bookyzo', 'bookyzo') }}</h2>
        <p class="text-sm text-muted-foreground">{{ __('A few quick steps — you\'ll be accepting bookings by the end of this.', 'bookyzo') }}</p>
      </div>

      <ul class="flex w-full flex-col gap-3 rounded-lg border border-border bg-muted/40 p-4 text-left text-sm">
        <li v-for="point in points" :key="point" class="flex items-start gap-3">
          <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
            <Check class="h-3.5 w-3.5" />
          </span>
          <span class="text-foreground">{{ point }}</span>
        </li>
      </ul>

      <div class="flex flex-col items-center gap-2">
        <Button size="lg" @click="$emit('next')">{{ __('Get started', 'bookyzo') }}</Button>
        <p class="text-xs text-muted-foreground">{{ __('Takes about two minutes. You can change everything later.', 'bookyzo') }}</p>
      </div>
    </CardContent>
  </Card>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
import { CalendarCheck, Check } from 'lucide-vue-next';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

defineEmits(['next']);

const points = [
  i18n.__('Choose what people book — an appointment, a table, an event or a time slot', 'bookyzo'),
  i18n.__('Set your opening hours and, if you charge, a price', 'bookyzo'),
  i18n.__('Get a booking form you can put on any page', 'bookyzo'),
];
</script>
