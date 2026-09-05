<template>
  <Card>
    <CardHeader>
      <CardTitle>Let's set up your first booking</CardTitle>
      <CardDescription>A few quick steps — you'll be accepting bookings by the end of this.</CardDescription>
    </CardHeader>
    <CardFooter class="justify-end">
      <Button @click="$emit('next')">Get started</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { Card, CardHeader, CardTitle, CardDescription, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

defineEmits(['next']);
</script>
