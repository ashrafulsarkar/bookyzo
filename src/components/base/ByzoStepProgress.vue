<template>
  <div class="flex items-center gap-1.5">
    <template v-for="(label, index) in labels" :key="label">
      <div
        :title="label"
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
      <div v-if="index < labels.length - 1" class="h-px flex-1" :class="index < current ? 'bg-primary' : 'bg-border'" />
    </template>
  </div>
</template>

<script setup>
// Numbered step dots + connectors for the dialog wizard
// (BookableItemWizard.vue). SetupWizard.vue keeps its own page-sized
// version of the same markup (larger dots, fixed-width connectors).
defineProps({
  labels: { type: Array, required: true },
  current: { type: Number, required: true },
});
</script>
