<template>
  <div class="flex items-center justify-between px-6 py-5">
    <div class="flex items-center gap-3">
      <div
        class="flex h-10 w-10 items-center justify-center rounded-lg text-white shadow-sm"
        style="background: linear-gradient(135deg, var(--byzo-primary), var(--byzo-primary-dark))"
      >
        <slot name="icon" />
      </div>
      <div>
        <h1 class="text-lg font-semibold leading-tight text-foreground">{{ title }}</h1>
        <p v-if="subtitle" class="text-sm text-muted-foreground">{{ subtitle }}</p>
      </div>
    </div>
    <div class="flex items-center gap-2">
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
