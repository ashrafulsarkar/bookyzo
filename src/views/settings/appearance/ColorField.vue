<template>
  <div class="flex flex-col gap-1.5">
    <Label :for="id">{{ label }}</Label>
    <div class="flex items-center gap-2">
      <!-- The native swatch and the hex text field edit the same value.
           The text field is the one that can hold an in-progress,
           not-yet-valid string (someone typing "#2f6" mid-edit), so it
           keeps its own draft and only writes upward once the value
           parses — otherwise every keystroke would fight the picker. -->
      <input
        :id="id"
        type="color"
        class="h-9 w-12 cursor-pointer rounded-md border border-input bg-background p-1"
        :value="modelValue"
        @input="commit($event.target.value)"
      />
      <Input
        v-model="draft"
        type="text"
        maxlength="7"
        class="w-28 font-mono"
        spellcheck="false"
        @blur="syncDraft"
      />
    </div>
    <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
  id: { type: String, required: true },
  modelValue: { type: String, required: true },
  label: { type: String, required: true },
  hint: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const draft = ref(props.modelValue);

// Only full 6-digit hex is accepted upward: the server normalizes with
// sanitize_hex_color(), and the native color input can't represent a
// 3-digit shorthand, so anything shorter would round-trip inconsistently.
const HEX = /^#[0-9a-fA-F]{6}$/;

watch(
  () => props.modelValue,
  (value) => {
    draft.value = value;
  }
);

watch(draft, (value) => {
  if (HEX.test(value)) {
    emit('update:modelValue', value.toLowerCase());
  }
});

function commit(value) {
  draft.value = value;
  emit('update:modelValue', value.toLowerCase());
}

// An abandoned half-typed value snaps back rather than silently
// persisting something the picker never accepted.
function syncDraft() {
  if (!HEX.test(draft.value)) {
    draft.value = props.modelValue;
  }
}
</script>
