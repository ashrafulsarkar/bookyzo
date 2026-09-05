<template>
  <Card>
    <CardHeader>
      <CardTitle>Customer information</CardTitle>
      <CardDescription>Name and email are always collected — a booking is meaningless without them.</CardDescription>
    </CardHeader>
    <CardContent>
      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="requirePhone" />
        Require a phone number too
      </label>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('back')">Back</Button>
      <Button @click="$emit('next', { require_phone: requirePhone })">Continue</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';

// No custom-field UI here — Free has no custom-field builder (that's
// B11/Pro), so this step must not tease one, per the Guideline-5 rule
// (DETAILED-SPEC.md §A1).
const props = defineProps({
  // Wizard data from an earlier pass, so Back from Pricing keeps the
  // toggle where the owner left it (SetupWizard.vue v-if-mounts steps).
  initial: { type: Object, default: null },
});
defineEmits(['next', 'back']);

const requirePhone = ref(Boolean(props.initial?.require_phone));
</script>
