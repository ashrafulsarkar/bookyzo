<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ __('Let\'s set up your first booking', 'bookyzo') }}</CardTitle>
      <CardDescription>{{ __('A few quick steps — you\'ll be accepting bookings by the end of this.', 'bookyzo') }}</CardDescription>
    </CardHeader>
    <CardFooter class="justify-end">
      <Button @click="$emit('next')">{{ __('Get started', 'bookyzo') }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { Card, CardHeader, CardTitle, CardDescription, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

defineEmits(['next']);
</script>
