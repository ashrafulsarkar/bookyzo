<template>
  <div class="flex flex-col items-center justify-center gap-2 text-sm text-muted-foreground" :class="size === 'compact' ? 'py-8' : 'py-12'">
    <Loader2Icon class="h-6 w-6 animate-spin text-primary" />
    <span>{{ label }}</span>
  </div>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
import { Loader2Icon } from 'lucide-vue-next';

// The one loading state for every admin screen (list tables, dialogs, the
// dashboard's own panels) — a spinning Loader2Icon (same icon Sonner.vue's
// toast already uses for its loading variant) instead of a bare "Loading…"
// text node, so a busy screen reads as "working" rather than "did this
// break". `size="compact"` trims the vertical padding for loaders that sit
// inside an already-small panel (e.g. a dashboard card) rather than filling
// a whole screen.
defineProps({
  label: { type: String, default: () => i18n.__('Loading…', 'bookyzo') },
  size: { type: String, default: 'default' },
});
</script>
