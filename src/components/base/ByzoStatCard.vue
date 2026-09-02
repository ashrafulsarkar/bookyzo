<template>
  <Card class="flex flex-col gap-1 p-5">
    <span class="text-sm text-muted-foreground">{{ label }}</span>
    <span class="text-2xl font-semibold">{{ value }}</span>
    <span v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</span>
  </Card>
</template>

<script setup>
// bookyzo-design skill's ByzoStatCard — a ByzoCard composite for
// dashboard metrics, used inside ByzoStatGrid's auto-fit layout.
import { Card } from '@/components/ui/card';

defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  hint: { type: String, default: '' },
});
</script>
