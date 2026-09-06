<template>
  <div class="flex items-start gap-2">
    <template v-for="(label, index) in labels" :key="label">
      <div class="flex shrink-0 flex-col items-center gap-1.5">
        <div
          :aria-current="index === current ? 'step' : undefined"
          :class="[
            'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-medium',
            index < current
              ? 'bg-primary text-primary-foreground'
              : index === current
                ? 'border-2 border-primary text-primary'
                : 'border border-border text-muted-foreground',
          ]"
        >
          {{ index + 1 }}
        </div>
        <span
          :class="[
            'whitespace-nowrap text-[11px] leading-none',
            index === current ? 'font-medium text-primary' : 'text-muted-foreground',
          ]"
        >
          {{ label }}
        </span>
      </div>
      <!-- mt-3 = half the dot's height, so the connector meets the dots'
           centre line rather than the top of the label column. -->
      <div v-if="index < labels.length - 1" class="mt-3 h-px flex-1" :class="index < current ? 'bg-primary' : 'bg-border'" />
    </template>
  </div>
</template>

<script setup>
// Numbered step dots + connectors for the dialog wizard
// (BookableItemWizard.vue). SetupWizard.vue keeps its own page-sized
// version of the same markup (larger dots, fixed-width connectors).
//
// The labels are rendered, not just passed: five bare numbered circles
// told the owner how many steps were left but nothing about what they
// were, and the wizard already computes real names for them (including
// "Dates" instead of "Availability" for an Event).
defineProps({
  labels: { type: Array, required: true },
  current: { type: Number, required: true },
});
</script>
