<template>
  <Card class="max-w-xl">
    <CardContent class="flex flex-col gap-4 pt-6">
      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium" for="name">Name</label>
        <input
          id="name"
          v-model="form.name"
          type="text"
          class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      </div>

      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium" for="description">Description</label>
        <textarea
          id="description"
          v-model="form.description"
          rows="3"
          class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      </div>

      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium" for="tags">Tags</label>
        <input
          id="tags"
          v-model="form.tags"
          type="text"
          placeholder="e.g. haircut, senior stylist"
          class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
        <p class="text-xs text-muted-foreground">Comma-separated.</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="duration">Duration (minutes)</label>
          <input
            id="duration"
            v-model.number="form.duration_minutes"
            type="number"
            min="5"
            step="5"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium" for="price">Price</label>
          <input
            id="price"
            v-model="form.price"
            type="number"
            min="0"
            step="0.01"
            placeholder="0.00"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
      </div>

      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium" for="status">Status</label>
        <select
          id="status"
          v-model="form.status"
          class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="archived">Archived</option>
        </select>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </CardContent>
    <CardFooter class="justify-between">
      <Button variant="outline" @click="$emit('cancel')">Cancel</Button>
      <Button :disabled="saving" @click="$emit('save')">{{ saving ? 'Saving…' : 'Save' }}</Button>
    </CardFooter>
  </Card>
</template>

<script setup>
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

defineProps({
  form: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['save', 'cancel']);

// photo field deferred (ROADMAP.md Phase 1 step 2 note) — needs a WP
// media-library picker (wp.media frame), not just a plain input; tracked
// as a follow-up, not stubbed with a fake upload control here.
</script>
