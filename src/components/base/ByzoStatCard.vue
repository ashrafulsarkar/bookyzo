<template>
  <Card class="flex flex-col gap-2.5 p-5">
    <div class="flex items-center justify-between">
      <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ label }}</span>
      <Badge v-if="trend" variant="outline" class="gap-1 border-transparent px-1.5 py-0" :style="trendStyle">
        <svg v-if="trend > 0" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
        <svg v-else-if="trend < 0" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 5v14M5 12l7 7 7-7" /></svg>
        {{ Math.abs(trend) }}%
      </Badge>
    </div>
    <span class="text-3xl font-semibold tabular-nums leading-none">{{ value }}</span>
    <span v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</span>
  </Card>
</template>

<script setup>
// bookyzo-design skill's ByzoStatCard — a ByzoCard composite for
// dashboard metrics, used inside ByzoStatGrid's auto-fit layout.
// Eyebrow label + big tabular figure + optional trend badge + hint line,
// matching dashboard-01's metric-card density (shadcn-vue/blocks) —
// reworked from the plain label/value/hint stack this card started as.
import { computed } from 'vue';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  hint: { type: String, default: '' },
  // Signed percent vs a named prior period — omit when there's nothing to compare against yet.
  trend: { type: Number, default: null },
});

const trendStyle = computed(() => {
  if (!props.trend) return {};
  const good = props.trend > 0;
  return {
    color: good ? 'var(--byzo-success)' : 'var(--byzo-danger)',
    background: good ? 'var(--byzo-success-bg)' : 'var(--byzo-danger-bg)',
  };
});
</script>
