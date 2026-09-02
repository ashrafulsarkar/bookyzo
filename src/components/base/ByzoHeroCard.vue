<template>
  <div class="byzo-hero relative overflow-hidden rounded-2xl p-7 text-white">
    <div class="relative z-10 flex flex-wrap items-center justify-between gap-6">
      <div class="min-w-0">
        <h2 class="mb-1 text-xl font-bold leading-tight">{{ title }}</h2>
        <p class="max-w-md text-sm text-white/85">{{ subtitle }}</p>
        <div v-if="$slots.chips" class="mt-4 flex flex-wrap gap-2">
          <slot name="chips" />
        </div>
      </div>
      <div v-if="$slots.figure" class="flex-shrink-0">
        <slot name="figure" />
      </div>
    </div>
  </div>
</template>

<script setup>
// A gradient "hero" summary card for top-level admin screens — the
// Dashboard's headline moment, one per view. Own component (not a
// ByzoCard preset) since the gradient/glow treatment is deliberately
// louder than the flat-surface look every other card uses; reserve it
// for a single hero per screen so it doesn't compete with itself.
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
});
</script>

<style scoped>
.byzo-hero {
  background: linear-gradient(125deg, var(--byzo-primary) 0%, var(--byzo-primary-dark) 70%, #1e3a8a 100%);
  box-shadow: 0 16px 34px -18px rgba(29, 78, 216, 0.55);
}

/* Soft decorative glow so the flat gradient has depth instead of
   reading as a solid block. */
.byzo-hero::before {
  content: '';
  position: absolute;
  top: -55%;
  right: -6%;
  width: 380px;
  height: 380px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.22), transparent 62%);
  pointer-events: none;
}
.byzo-hero::after {
  content: '';
  position: absolute;
  bottom: -65%;
  left: 6%;
  width: 300px;
  height: 300px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(0, 0, 0, 0.12), transparent 60%);
  pointer-events: none;
}

:global(.byzo-hero-chip) {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.26);
  color: rgba(255, 255, 255, 0.92);
}
</style>
