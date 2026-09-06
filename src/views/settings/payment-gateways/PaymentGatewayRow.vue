<template>
  <div class="rounded-lg border bg-card">
    <button
      type="button"
      class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left"
      @click="expanded = !expanded"
    >
      <div class="flex items-center gap-3">
        <div
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-sm font-semibold text-white"
          :style="{ backgroundColor: gateway.color }"
        >
          {{ monogram }}
        </div>
        <div>
          <p class="text-sm font-medium leading-tight">{{ gateway.label }}</p>
          <p class="text-sm text-muted-foreground">{{ gateway.description }}</p>
        </div>
      </div>

      <div class="flex shrink-0 items-center gap-3">
        <ByzoBadge :status="modelValue.enabled ? 'active' : 'inactive'">
          {{ modelValue.enabled ? 'Enabled' : 'Disabled' }}
        </ByzoBadge>
        <!-- stop propagation so flipping the switch doesn't also toggle
             the row's expand/collapse — they're independent actions. -->
        <Switch
          :model-value="modelValue.enabled"
          @update:model-value="$emit('update:modelValue', { ...modelValue, enabled: $event })"
          @click.stop
        />
        <ChevronDown class="h-4 w-4 text-muted-foreground transition-transform" :class="{ 'rotate-180': expanded }" />
      </div>
    </button>

    <div v-if="expanded" class="border-t px-4 py-4">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import { Switch } from '@/components/ui/switch';
import ByzoBadge from '@/components/base/ByzoBadge.vue';

const props = defineProps({
  gateway: { type: Object, required: true },
  modelValue: { type: Object, required: true },
});
defineEmits(['update:modelValue']);

const expanded = ref(false);
const monogram = computed(() => props.gateway.label.charAt(0).toUpperCase());
</script>
