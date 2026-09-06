<template>
  <div>
    <h2>Pick a time</h2>
    <p>{{ item.name }}</p>

    <WidgetLoading v-if="loading" />
    <div v-else-if="error" class="error">{{ error }}</div>
    <div v-else-if="dates.length === 0" class="empty">No available times in the next few weeks.</div>
    <template v-else>
      <div class="date-list">
        <button
          v-for="date in dates"
          :key="date.key"
          type="button"
          class="chip"
          :class="{ selected: date.key === selectedDateKey }"
          @click="selectedDateKey = date.key"
        >
          {{ date.label }}
        </button>
      </div>

      <div class="slot-list" style="margin-top: 12px">
        <button
          v-for="slot in slotsForSelectedDate"
          :key="slot.id"
          type="button"
          class="chip"
          @click="$emit('selected', slot)"
        >
          {{ formatTime(slot.start_datetime) }}
        </button>
      </div>
    </template>

    <div class="actions">
      <button type="button" class="btn btn-outline" @click="$emit('back')">Back</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { getSlots } from '../api';
import WidgetLoading from '../WidgetLoading.vue';

const props = defineProps({
  item: { type: Object, required: true },
});
const emit = defineEmits(['selected', 'back']);

const slots = ref([]);
const loading = ref(true);
const error = ref('');
const selectedDateKey = ref('');

function dateKey(iso) {
  return iso.slice(0, 10);
}

function formatTime(iso) {
  return new Date(iso.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

const dates = computed(() => {
  const seen = new Map();
  slots.value.forEach((slot) => {
    const key = dateKey(slot.start_datetime);
    if (!seen.has(key)) {
      seen.set(key, new Date(slot.start_datetime.replace(' ', 'T')));
    }
  });
  return Array.from(seen.entries())
    .sort((a, b) => a[0].localeCompare(b[0]))
    .map(([key, date]) => ({
      key,
      label: date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }),
    }));
});

const slotsForSelectedDate = computed(() => slots.value.filter((s) => dateKey(s.start_datetime) === selectedDateKey.value));

watch(dates, (list) => {
  if (list.length > 0 && !list.some((d) => d.key === selectedDateKey.value)) {
    selectedDateKey.value = list[0].key;
  }
});

onMounted(async () => {
  try {
    slots.value = await getSlots(props.item.id);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
});
</script>
