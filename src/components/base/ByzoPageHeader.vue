<template>
  <!-- Wraps rather than overflows: on a phone the title block and the
       action buttons each take a full row instead of the actions being
       pushed off the right edge. `min-w-0` lets the title actually
       truncate/wrap inside the flex child instead of forcing the row
       wider than the screen. -->
  <div class="byzo-page-header flex flex-wrap items-center justify-between gap-x-4 gap-y-3 px-4 py-5 sm:px-6">
    <div class="flex min-w-0 items-center gap-3">
      <div
        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg text-white shadow-sm"
        style="background: linear-gradient(135deg, var(--byzo-primary), var(--byzo-primary-dark))"
      >
        <slot name="icon" />
      </div>
      <div class="min-w-0">
        <h1 class="text-lg font-semibold leading-tight text-foreground">{{ title }}</h1>
        <p v-if="subtitle" class="text-sm text-muted-foreground">{{ subtitle }}</p>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
// bookyzo-design skill's ByzoPageHeader: icon badge + title + subtitle +
// trailing action slot. Every top-level admin route starts with one.
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
});
</script>
