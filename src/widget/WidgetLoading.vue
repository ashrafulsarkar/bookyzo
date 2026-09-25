<template>
  <div class="loading">
    <span class="spinner"></span>
    <span>{{ label }}</span>
  </div>
</template>

<script setup>
import { i18n } from '@/lib/i18n';
// The one loading state for the public booking widget's steps (bookable
// item picker, slot picker, Stripe payment form) — a plain CSS spinner
// since the widget ships its own stylesheet (widget.css) with no
// Tailwind/lucide build step, unlike the wp-admin Vue app's ByzoLoading.
defineProps({
  label: { type: String, default: () => i18n.__('Loading…', 'bookyzo') },
});
</script>
