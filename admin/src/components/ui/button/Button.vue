<template>
  <button :class="cn(buttonVariants({ variant, size }), $attrs.class)" v-bind="omitClass($attrs)">
    <slot />
  </button>
</template>

<script setup>
import { cn } from '@/lib/utils';
import { buttonVariants } from './variants';

defineOptions({ inheritAttrs: false });

defineProps({
  variant: { type: String, default: 'default' },
  size: { type: String, default: 'default' },
});

function omitClass(attrs) {
  const { class: _class, ...rest } = attrs;
  return rest;
}
</script>
