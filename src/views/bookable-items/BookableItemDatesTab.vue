<template>
  <div class="flex flex-col gap-4">
    <Card :class="embedded ? 'border-0 shadow-none' : 'max-w-xl'">
      <CardHeader>
        <CardTitle>Add a date</CardTitle>
        <CardDescription>Events have no weekly schedule — enter each occurrence directly (DETAILED-SPEC.md §1.3).</CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="occurrence-date">Date</Label>
          <Input id="occurrence-date" v-model="draft.date" type="date" class="h-9 w-48" />
        </div>

        <div class="flex items-center gap-2">
          <Input v-model="draft.start_time" type="time" class="h-8 w-auto" />
          <span class="text-sm text-muted-foreground">to</span>
          <Input v-model="draft.end_time" type="time" class="h-8 w-auto" />
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </CardContent>
      <CardFooter class="justify-end">
        <Button :disabled="!draft.date || adding" @click="addOccurrence">{{ adding ? 'Adding…' : 'Add date' }}</Button>
      </CardFooter>
    </Card>

    <ByzoLoading v-if="loading" size="compact" />

    <Card v-else-if="occurrences.length > 0" :class="['overflow-hidden', embedded ? 'mx-6' : 'max-w-xl']">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Date</TableHead>
            <TableHead>Time</TableHead>
            <TableHead>Status</TableHead>
            <TableHead></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="occurrence in occurrences" :key="occurrence.id">
            <TableCell>{{ formatDate(occurrence.start_datetime) }}</TableCell>
            <TableCell class="text-muted-foreground">
              {{ formatTime(occurrence.start_datetime) }}–{{ formatTime(occurrence.end_datetime) }}
            </TableCell>
            <TableCell><ByzoBadge :status="occurrence.status">{{ occurrence.status }} · {{ occurrence.capacity_remaining }} left</ByzoBadge></TableCell>
            <TableCell class="text-right">
              <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="removeOccurrence(occurrence.id)">
                Remove
              </Button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </Card>

    <Card v-else :class="['py-10 text-center text-sm text-muted-foreground', embedded ? 'mx-6' : 'max-w-xl']">
      No dates yet — add the first occurrence above.
    </Card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import ByzoBadge from '@/components/base/ByzoBadge.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import { api } from '@/api/client';

const props = defineProps({
  bookableItemId: { type: [String, Number], required: true },
  // True when rendered inside BookableItemEditDialog.vue: drops the form
  // card's own border/shadow (it would otherwise sit as a bordered card
  // inside the dialog's bordered surface) and the max-w-xl cap, so the
  // tab fills the dialog. The class can't just fall through from the
  // parent as it does for the single-Card tabs — this root is a wrapper
  // <div> with several cards inside.
  embedded: { type: Boolean, default: false },
});

const occurrences = ref([]);
const loading = ref(true);
const adding = ref(false);
const error = ref('');

const draft = ref({
  date: '',
  start_time: '19:00',
  end_time: '22:00',
});

function formatDate(datetime) {
  return new Date(datetime.replace(' ', 'T')).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(datetime) {
  return new Date(datetime.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

async function load() {
  loading.value = true;
  try {
    occurrences.value = await api.get(`/bookable-items/${props.bookableItemId}/slots`);
  } catch (e) {
    error.value = e.message || 'Could not load dates.';
  } finally {
    loading.value = false;
  }
}

async function addOccurrence() {
  error.value = '';
  adding.value = true;
  try {
    await api.post(`/bookable-items/${props.bookableItemId}/slots`, { ...draft.value });
    draft.value.date = '';
    await load();
  } catch (e) {
    error.value = e.message || 'Could not add this date.';
  } finally {
    adding.value = false;
  }
}

async function removeOccurrence(id) {
  try {
    occurrences.value = await api.delete(`/bookable-items/${props.bookableItemId}/slots/${id}`);
  } catch (e) {
    // Server refuses with 409 when the occurrence has bookings against it
    // (BYZO_Slot::delete_occurrence()) — surface that message as-is rather
    // than a generic one, it already explains exactly why.
    toast.error(e.message || 'Could not remove this date.');
  }
}

onMounted(load);
</script>
