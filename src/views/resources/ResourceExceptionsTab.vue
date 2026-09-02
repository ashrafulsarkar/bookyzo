<template>
  <div class="flex flex-col gap-4">
    <Card class="max-w-xl">
      <CardHeader>
        <CardTitle>Add a date exception</CardTitle>
        <CardDescription>Block a holiday, or override hours for a specific date.</CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
          <Label for="exception-date">Date</Label>
          <Input id="exception-date" v-model="draft.date" type="date" class="h-9 w-48" />
        </div>

        <label class="flex items-center gap-2 text-sm">
          <Switch v-model="draft.is_closed" />
          Fully closed this day
        </label>

        <div v-if="!draft.is_closed" class="flex items-center gap-2">
          <Input v-model="draft.start_time" type="time" class="h-8 w-auto" />
          <span class="text-sm text-muted-foreground">to</span>
          <Input v-model="draft.end_time" type="time" class="h-8 w-auto" />
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </CardContent>
      <CardFooter class="justify-end">
        <Button :disabled="!draft.date || adding" @click="addException">{{ adding ? 'Adding…' : 'Add exception' }}</Button>
      </CardFooter>
    </Card>

    <Card v-if="!loading && exceptions.length > 0" class="max-w-xl overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Date</TableHead>
            <TableHead>Hours</TableHead>
            <TableHead></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="exception in exceptions" :key="exception.id">
            <TableCell>{{ exception.date }}</TableCell>
            <TableCell class="text-muted-foreground">
              {{ exception.is_closed ? 'Closed' : `${exception.start_time.slice(0, 5)}–${exception.end_time.slice(0, 5)}` }}
            </TableCell>
            <TableCell class="text-right">
              <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="removeException(exception.id)">
                Remove
              </Button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
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
import { Switch } from '@/components/ui/switch';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { api } from '@/api/client';

const props = defineProps({
  resourceId: { type: [String, Number], required: true },
});

const exceptions = ref([]);
const loading = ref(true);
const adding = ref(false);
const error = ref('');

const draft = ref({
  date: '',
  is_closed: true,
  start_time: '09:00',
  end_time: '17:00',
});

async function load() {
  loading.value = true;
  try {
    const availability = await api.get(`/resources/${props.resourceId}/availability`);
    exceptions.value = availability.exceptions;
  } catch (e) {
    error.value = e.message || 'Could not load exceptions.';
  } finally {
    loading.value = false;
  }
}

async function addException() {
  error.value = '';
  adding.value = true;
  try {
    const payload = { date: draft.value.date, is_closed: draft.value.is_closed };
    if (!draft.value.is_closed) {
      payload.start_time = draft.value.start_time;
      payload.end_time = draft.value.end_time;
    }
    const result = await api.post(`/resources/${props.resourceId}/availability/exceptions`, payload);
    exceptions.value = result.exceptions;
    draft.value.date = '';
  } catch (e) {
    error.value = e.message || 'Could not add this exception.';
  } finally {
    adding.value = false;
  }
}

async function removeException(id) {
  try {
    const result = await api.delete(`/resources/${props.resourceId}/availability/exceptions/${id}`);
    exceptions.value = result.exceptions;
  } catch (e) {
    toast.error(e.message || 'Could not remove this exception.');
  }
}

onMounted(load);
</script>
